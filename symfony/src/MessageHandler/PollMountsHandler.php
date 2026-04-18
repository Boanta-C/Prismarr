<?php

namespace App\MessageHandler;

use App\Entity\Infrastructure\Device;
use App\Entity\Infrastructure\Metric;
use App\Entity\Infrastructure\ServiceStatus;
use App\Message\PollMountsMessage;
use App\Service\Mac\MacAgentClient;
use App\Service\WorkerHealthService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Poll des 5 mounts NFS du Mac host (films, series, animes-films,
 * animes-series, downloads) via l'endpoint /mounts de metrics-agent.
 *
 * - Met à jour 5 ServiceStatus (prefix `mount.<name>`, statut up/down).
 * - Stocke size_gb / free_gb / used_percent en Metric (throttle 60s).
 * - Publie sur Mercure topic `/argos/mounts` à chaque passage.
 * - Remonte également `system.mount_nas.last_exit` et `.last_failed` via
 *   l'endpoint /mount-log (dernier run du LaunchDaemon com.nas.mounts).
 */
#[AsMessageHandler]
class PollMountsHandler
{
    private ?\DateTimeImmutable $lastMetricFlushAt = null;
    private EntityManagerInterface $em;

    public function __construct(
        private readonly MacAgentClient      $agent,
        private readonly ManagerRegistry     $doctrine,
        private readonly LoggerInterface     $logger,
        private readonly HubInterface        $hub,
        private readonly WorkerHealthService $health,
    ) {
        $this->em = $doctrine->getManager();
    }

    public function __invoke(PollMountsMessage $message): void
    {
        $this->resetEmIfClosed();

        try {
            $data = $this->agent->fetchMounts();
            if ($data === null || empty($data['mounts'])) {
                // Agent injoignable : ne pas cascader d'erreur worker — c'est un état courant
                // (Mac endormi, agent redémarré, etc.). On log debug via le client déjà.
                return;
            }

            $device        = $this->getDevice();
            $shouldPersist = $this->lastMetricFlushAt === null
                || $this->lastMetricFlushAt < new \DateTimeImmutable('-60 seconds');

            foreach ($data['mounts'] as $mount) {
                $name = 'mount.' . $mount['name'];

                $svc = $this->getOrCreateService($device, $name);
                $svc->setStatus($mount['status']);
                $svc->setCheckedAt(new \DateTimeImmutable());
                $svc->setUrl($mount['source'] ?? null);
                $svc->setHttpCode(null);
                $svc->setResponseTimeMs(null);

                if ($shouldPersist && $mount['status'] === 'up') {
                    if ($mount['size_gb'] !== null) {
                        $this->addMetric($device, "{$name}.size_gb",      (float) $mount['size_gb'],      'GB');
                    }
                    if ($mount['free_gb'] !== null) {
                        $this->addMetric($device, "{$name}.free_gb",      (float) $mount['free_gb'],      'GB');
                    }
                    if ($mount['used_percent'] !== null) {
                        $this->addMetric($device, "{$name}.used_percent", (float) $mount['used_percent'], '%');
                    }
                }
            }

            // Log du LaunchDaemon mount-nas (dernier run + échec)
            $log = $this->agent->fetchMountLog();
            if ($log !== null && !empty($log['available']) && $shouldPersist) {
                if (isset($log['last_failed'])) {
                    $this->addMetric($device, 'system.mount_nas.last_failed', (float) $log['last_failed'], '');
                }
                if (isset($log['last_exit'])) {
                    $this->addMetric($device, 'system.mount_nas.last_exit', (float) $log['last_exit'], '');
                }
            }

            if ($shouldPersist) {
                $this->em->flush();
                $this->lastMetricFlushAt = new \DateTimeImmutable();
            } else {
                $this->em->flush(); // persist uniquement les ServiceStatus (pas les Metric)
            }

            $this->publishToMercure($data, $log);
            $this->health->reportSuccess('worker:mounts');
        } catch (\Throwable $e) {
            $this->logger->error('PollMountsHandler : ' . $e->getMessage());
            $this->health->reportFailure('worker:mounts', 'mac-mini', 'Mounts poll en erreur : ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------

    private function publishToMercure(array $data, ?array $log): void
    {
        try {
            $payload = [
                'mounts'     => $data['mounts'] ?? [],
                'up_count'   => $data['up_count'] ?? 0,
                'down_count' => $data['down_count'] ?? 0,
                'total'      => $data['total'] ?? 0,
                'last_seen'  => date('H:i:s'),
            ];
            if ($log !== null && !empty($log['available'])) {
                $payload['log'] = [
                    'last_run'    => $log['last_run'] ?? null,
                    'last_exit'   => $log['last_exit'] ?? null,
                    'last_failed' => $log['last_failed'] ?? null,
                ];
            }
            $this->hub->publish(new Update('/argos/mounts', json_encode($payload)));
        } catch (\Throwable $e) {
            $this->logger->warning('PollMountsHandler : Mercure publish failed — ' . $e->getMessage());
        }
    }

    private function getOrCreateService(Device $device, string $name): ServiceStatus
    {
        $svc = $this->em->getRepository(ServiceStatus::class)
            ->findOneBy(['device' => $device, 'name' => $name]);

        if (!$svc) {
            $svc = new ServiceStatus();
            $svc->setDevice($device);
            $svc->setName($name);
            $this->em->persist($svc);
        }

        return $svc;
    }

    private function addMetric(Device $device, string $name, float $value, string $unit): void
    {
        $m = new Metric();
        $m->setDevice($device);
        $m->setName($name);
        $m->setValue($value);
        $m->setUnit($unit);
        $this->em->persist($m);
    }

    private function getDevice(): Device
    {
        $device = $this->em->getRepository(Device::class)->findOneBy(['hostname' => 'mac-mini']);
        if (!$device) {
            throw new \RuntimeException('Device mac-mini introuvable en base.');
        }
        return $device;
    }

    private function resetEmIfClosed(): void
    {
        if (!$this->em->isOpen()) {
            $this->doctrine->resetManager();
            $this->em = $this->doctrine->getManager();
        }
    }
}
