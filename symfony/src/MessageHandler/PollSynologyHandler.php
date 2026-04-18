<?php

namespace App\MessageHandler;

use App\Entity\Infrastructure\Device;
use App\Entity\Infrastructure\Metric;
use App\Entity\Infrastructure\ServiceStatus;
use App\Message\PollSynologyMessage;
use App\Service\Synology\SynologyClient;
use App\Service\WorkerHealthService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class PollSynologyHandler
{
    /** Throttle métriques DB — une écriture toutes les 60s. */
    private ?\DateTimeImmutable $lastMetricFlushAt = null;

    private EntityManagerInterface $em;

    public function __construct(
        private readonly SynologyClient      $synology,
        private readonly ManagerRegistry     $doctrine,
        private readonly LoggerInterface     $logger,
        private readonly HubInterface        $hub,
        private readonly WorkerHealthService $health,
    ) {
        $this->em = $doctrine->getManager();
    }

    public function __invoke(PollSynologyMessage $message): void
    {
        $this->resetEmIfClosed();

        try {
            $device = $this->getOrCreateNas();
            $data   = $this->synology->getSystemData();

            if ($data === null) {
                $device->setStatus('offline');
                $device->setLastSeenAt(new \DateTimeImmutable());
                $this->em->flush();
                $this->logger->warning('PollSynologyHandler : données NAS indisponibles.');
                $this->health->reportFailure('worker:synology', 'ds-medusa', 'API Synology inaccessible.');
                return;
            }

            $dsm = $this->getOrCreateService($device, 'synology-dsm');
            $dsm->setStatus('up');
            $dsm->setCheckedAt(new \DateTimeImmutable());
            $dsm->setUrl(null);
            $dsm->setHttpCode(null);
            $dsm->setResponseTimeMs(null);

            // Métriques CPU / RAM %
            $this->addMetric($device, 'synology.cpu', $data['cpu'], '%');
            $this->addMetric($device, 'synology.mem', $data['mem'], '%');

            if ($data['temp'] !== null) {
                $this->addMetric($device, 'synology.temp', $data['temp'], '°C');
            }

            // Mémoire en MB
            if (($data['mem_total_mb'] ?? 0) > 0) {
                $this->addMetric($device, 'synology.mem_total_mb', $data['mem_total_mb'], 'MB');
                $this->addMetric($device, 'synology.mem_used_mb',  $data['mem_used_mb'],  'MB');
            }

            // Volumes
            foreach ($data['volumes'] as $vol) {
                $volId = preg_replace('/[^a-z0-9_]/', '_', strtolower($vol['id']));
                $this->addMetric($device, "synology.{$volId}.used_percent", $vol['percent'],  '%');
                $this->addMetric($device, "synology.{$volId}.used_gb",      $vol['used_gb'],  'GB');
                $this->addMetric($device, "synology.{$volId}.total_gb",     $vol['total_gb'], 'GB');

                $volService = $this->getOrCreateService($device, "synology-{$volId}");
                $volService->setStatus($vol['status'] === 'normal' ? 'up' : 'degraded');
                $volService->setCheckedAt(new \DateTimeImmutable());
                $volService->setUrl(null);
                $volService->setHttpCode(null);
                $volService->setResponseTimeMs(null);
            }

            // Températures disques
            foreach ($data['disks'] ?? [] as $disk) {
                if ($disk['temp'] !== null) {
                    $diskId = preg_replace('/[^a-z0-9_]/', '_', strtolower($disk['id']));
                    $this->addMetric($device, "synology.disk.{$diskId}.temp", (float) $disk['temp'], '°C');
                }
            }

            // Interfaces réseau (TX/RX en bytes/s)
            foreach ($data['network'] ?? [] as $iface) {
                $ifaceId = preg_replace('/[^a-z0-9_]/', '_', $iface['device']);
                $this->addMetric($device, "synology.net.{$ifaceId}.tx", (float) $iface['tx'], 'B/s');
                $this->addMetric($device, "synology.net.{$ifaceId}.rx", (float) $iface['rx'], 'B/s');
            }

            // ── Containers Docker NAS ──────────────────────────────────────
            $shouldPersistMetrics = $this->lastMetricFlushAt === null
                || $this->lastMetricFlushAt < new \DateTimeImmutable('-60 seconds');

            $mercureContainers = [];
            $seenDockerNames   = [];

            if (!empty($data['docker'])) {
                $runningIds  = array_map(
                    fn($c) => $c['id'],
                    array_filter($data['docker'], fn($c) => ($c['state'] ?? null) === 'running')
                );
                $dockerStats = $this->synology->getContainerStatsByIds($runningIds);

                foreach ($data['docker'] as $container) {
                    $cName           = $container['name'];
                    $seenDockerNames[] = $cName;

                    $svc = $this->getOrCreateService($device, 'docker.' . $cName);
                    $normalizedStatus = $container['status'] ?? 'unknown';
                    $svc->setStatus($normalizedStatus);
                    $svc->setCheckedAt(new \DateTimeImmutable());
                    $svc->setUrl(null);
                    $svc->setHttpCode(null);
                    $svc->setResponseTimeMs(null);

                    $stats = $dockerStats[$container['id']] ?? null;

                    if ($shouldPersistMetrics && $stats !== null) {
                        $this->addMetric($device, "docker.{$cName}.cpu",    $stats['cpu'],          '%');
                        $this->addMetric($device, "docker.{$cName}.mem",    $stats['mem'],          '%');
                        $this->addMetric($device, "docker.{$cName}.mem_mb", (float) $stats['mem_mb'], 'MB');
                    }

                    $mercureContainers[] = [
                        'name'   => $cName,
                        'image'  => $container['image'],
                        'status' => $normalizedStatus,
                        'cpu'    => $stats ? round($stats['cpu'], 1) : null,
                        'mem'    => $stats ? round($stats['mem'], 1) : null,
                        'mem_mb' => $stats ? $stats['mem_mb'] : null,
                    ];
                }

                // Suppression des containers fantômes NAS
                $allNasSvcs = $this->em->getRepository(ServiceStatus::class)->findBy(['device' => $device]);
                foreach ($allNasSvcs as $svc) {
                    if (!str_starts_with($svc->getName(), 'docker.')) {
                        continue;
                    }
                    $cName = substr($svc->getName(), 7);
                    if (!in_array($cName, $seenDockerNames, true)) {
                        $this->em->remove($svc);
                        $this->logger->info("PollSynologyHandler : suppression container fantôme NAS '{$cName}'.");
                    }
                }
            }

            if ($shouldPersistMetrics) {
                $this->lastMetricFlushAt = new \DateTimeImmutable();
            }
            // ──────────────────────────────────────────────────────────────

            $device->setStatus('online');
            $device->setLastSeenAt(new \DateTimeImmutable());
            $this->em->flush();

            $this->publishToMercure($data, $mercureContainers);
            $this->logger->info(sprintf(
                'PollSynologyHandler : CPU %.1f%% | RAM %.1f%% (%d/%d MB) | %d volume(s) | %d disque(s) | %d container(s) Docker.',
                $data['cpu'], $data['mem'], $data['mem_used_mb'], $data['mem_total_mb'],
                count($data['volumes']), count($data['disks']), count($mercureContainers),
            ));

            $this->health->reportSuccess('worker:synology');
        } catch (\Throwable $e) {
            $this->logger->error('PollSynologyHandler : ' . $e->getMessage());
            $this->health->reportFailure('worker:synology', 'ds-medusa', 'Worker Synology en erreur : ' . $e->getMessage());
        }
    }

    private function resetEmIfClosed(): void
    {
        if (!$this->em->isOpen()) {
            $this->doctrine->resetManager();
            $this->em = $this->doctrine->getManager();
        }
    }

    private function publishToMercure(array $data, array $containers = []): void
    {
        try {
            $volumes = array_map(fn($v) => [
                'id'       => $v['id'],
                'percent'  => $v['percent'],
                'used_gb'  => $v['used_gb'],
                'total_gb' => $v['total_gb'],
                'status'   => $v['status'],
            ], $data['volumes']);

            // Disques : uniquement id, temp, status pour le SSE
            $disks = array_map(fn($d) => [
                'id'      => $d['id'],
                'model'   => $d['model'],
                'temp'    => $d['temp'],
                'status'  => $d['status'],
                'size_gb' => $d['size_gb'],
            ], $data['disks'] ?? []);

            // Réseau
            $network = array_map(fn($n) => [
                'device' => $n['device'],
                'tx'     => $n['tx'],
                'rx'     => $n['rx'],
            ], $data['network'] ?? []);

            $this->hub->publish(new Update(
                '/argos/metrics/nas',
                json_encode([
                    'cpu'          => $data['cpu'],
                    'mem'          => $data['mem'],
                    'mem_total_mb' => $data['mem_total_mb'],
                    'mem_used_mb'  => $data['mem_used_mb'],
                    'temp'         => $data['temp'],
                    'volumes'      => $volumes,
                    'disks'        => $disks,
                    'network'      => $network,
                    'last_seen'    => date('H:i:s'),
                    'containers'   => $containers,
                ])
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('PollSynologyHandler : Mercure publish failed — ' . $e->getMessage());
        }
    }

    private function getOrCreateNas(): Device
    {
        $device = $this->em->getRepository(Device::class)->findOneBy(['hostname' => 'ds-medusa']);

        if (!$device) {
            $device = new Device();
            $device->setName('DS-MEDUSA (NAS)');
            $device->setType('nas');
            $device->setHostname('ds-medusa');
            $device->setIpAddress('192.168.10.230');
            $device->setOs('DSM');
            $device->setIsMonitored(true);
            $this->em->persist($device);
            $this->em->flush();
            $this->logger->info('Device "DS-MEDUSA" créé automatiquement.');
        }

        return $device;
    }

    private function getOrCreateService(Device $device, string $name): ServiceStatus
    {
        $service = $this->em->getRepository(ServiceStatus::class)
            ->findOneBy(['device' => $device, 'name' => $name]);

        if (!$service) {
            $service = new ServiceStatus();
            $service->setDevice($device);
            $service->setName($name);
            $this->em->persist($service);
        }

        return $service;
    }

    private function addMetric(Device $device, string $name, float $value, string $unit): void
    {
        $metric = new Metric();
        $metric->setDevice($device);
        $metric->setName($name);
        $metric->setValue($value);
        $metric->setUnit($unit);
        $this->em->persist($metric);
    }
}
