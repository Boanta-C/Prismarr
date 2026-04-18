<?php

namespace App\MessageHandler;

use App\Entity\Infrastructure\Device;
use App\Entity\Infrastructure\Metric;
use App\Entity\Infrastructure\ServiceStatus;
use App\Message\PollDockerMessage;
use App\Service\Docker\DockerClient;
use App\Service\WorkerHealthService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class PollDockerHandler
{
    /** Throttle BDD metrics — une écriture toutes les 60s (ServiceStatus écrit toujours). */
    private ?\DateTimeImmutable $lastMetricFlushAt = null;

    private EntityManagerInterface $em;

    public function __construct(
        private readonly DockerClient $docker,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
        private readonly HubInterface $hub,
        private readonly WorkerHealthService $health,
    ) {
        $this->em = $doctrine->getManager();
    }

    public function __invoke(PollDockerMessage $message): void
    {
        $this->resetEmIfClosed();

        try {
            $device     = $this->getOrCreateMacMini();
            $containers = $this->docker->getContainers();

            if (empty($containers)) {
                $this->logger->warning('PollDockerHandler : aucun conteneur retourné.');
                return;
            }

            $shouldPersistMetrics = $this->lastMetricFlushAt === null
                || $this->lastMetricFlushAt < new \DateTimeImmutable('-60 seconds');

            $seenNames    = [];
            $mercureItems = [];

            foreach ($containers as $container) {
                $name        = $container['name'];
                $seenNames[] = $name;

                $service = $this->em->getRepository(ServiceStatus::class)
                    ->findOneBy(['device' => $device, 'name' => $name]);

                if (!$service) {
                    $service = new ServiceStatus();
                    $service->setDevice($device);
                    $service->setName($name);
                    $this->em->persist($service);
                }

                $exitCode = null;
                if (preg_match('/Exited \((-?\d+)\)/', (string) ($container['status'] ?? ''), $m)) {
                    $exitCode = (int) $m[1];
                }
                // Exit codes d'arrêt volontaire : 0 (clean), 143 (SIGTERM), 137 (SIGKILL)
                $cleanExit = [0, 137, 143];
                $status = match ($container['state']) {
                    'running'            => 'up',
                    'paused'             => 'down',
                    'exited'             => ($exitCode !== null && !in_array($exitCode, $cleanExit, true)) ? 'error' : 'down',
                    'dead', 'restarting' => 'error',
                    'created'            => (($container['created'] ?? 0) > 0 && (time() - $container['created']) > 120) ? 'error' : 'down',
                    default              => 'unknown',
                };

                $service->setStatus($status);
                $service->setUrl(null);
                $service->setHttpCode(null);
                $service->setCheckedAt(new \DateTimeImmutable());

                $uptimeSeconds = null;
                if ($container['state'] === 'running' && $container['created'] > 0) {
                    $uptimeSeconds = time() - $container['created'];
                    $service->setResponseTimeMs($uptimeSeconds);
                } else {
                    $service->setResponseTimeMs(null);
                }

                $stats = null;
                if ($container['state'] === 'running') {
                    $stats = $this->fetchStats($container);
                    if ($stats && $shouldPersistMetrics) {
                        $prefix = 'docker.' . $name;
                        $this->addMetric($device, $prefix . '.cpu',    $stats['cpu_percent'],                       '%');
                        $this->addMetric($device, $prefix . '.mem',    $stats['mem_percent'],                       '%');
                        $this->addMetric($device, $prefix . '.mem_mb', round($stats['mem_usage'] / 1024 / 1024, 1), 'MB');
                    }
                }

                $mercureItems[] = [
                    'name'   => $name,
                    'status' => $status,
                    'cpu'    => $stats ? round($stats['cpu_percent'], 1) : null,
                    'mem'    => $stats ? round($stats['mem_percent'], 1) : null,
                    'mem_mb' => $stats ? round($stats['mem_usage'] / 1024 / 1024, 1) : null,
                    'uptime' => $uptimeSeconds,
                ];
            }

            // Suppression des containers fantômes (ne PAS toucher aux autres prefixes)
            $allServices = $this->em->getRepository(ServiceStatus::class)->findBy(['device' => $device]);
            foreach ($allServices as $service) {
                $sname = $service->getName();
                if (str_starts_with($sname, 'docker.')
                    || str_starts_with($sname, 'synology')
                    || str_starts_with($sname, 'mount.')
                    || str_starts_with($sname, 'system.')
                    || str_starts_with($sname, 'db_integrity.')
                    || str_starts_with($sname, 'cleanup.')) {
                    continue;
                }
                if (!in_array($sname, $seenNames, true)) {
                    $this->em->remove($service);
                    $this->logger->info("PollDockerHandler : suppression du container fantôme '{$sname}'.");
                }
            }

            $device->setStatus('online');
            $device->setLastSeenAt(new \DateTimeImmutable());
            $this->em->flush();

            if ($shouldPersistMetrics) {
                $this->lastMetricFlushAt = new \DateTimeImmutable();
            }

            $up    = count(array_filter($mercureItems, fn($c) => $c['status'] === 'up'));
            $total = count($mercureItems);

            $this->publishToMercure($up, $total, $mercureItems);
            $this->logger->info(sprintf('PollDockerHandler : %d conteneurs, push Mercure OK.', $total));

            $this->health->reportSuccess('worker:docker');
        } catch (\Throwable $e) {
            $this->logger->error('PollDockerHandler : ' . $e->getMessage());
            $this->health->reportFailure('worker:docker', 'mac-mini', 'Worker Docker en erreur : ' . $e->getMessage());
        }
    }

    private function resetEmIfClosed(): void
    {
        if (!$this->em->isOpen()) {
            $this->doctrine->resetManager();
            $this->em = $this->doctrine->getManager();
        }
    }

    private function fetchStats(array $container): ?array
    {
        try {
            return $this->docker->getContainerStats($container['id']);
        } catch (\Throwable $e) {
            $this->logger->warning("Stats Docker impossibles pour {$container['name']}: {$e->getMessage()}");
            return null;
        }
    }

    private function publishToMercure(int $up, int $total, array $containers): void
    {
        try {
            $this->hub->publish(new Update(
                '/argos/containers',
                json_encode([
                    'up'         => $up,
                    'total'      => $total,
                    'containers' => $containers,
                    'last_seen'  => date('H:i:s'),
                ])
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('PollDockerHandler : Mercure publish failed — ' . $e->getMessage());
        }
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

    private function getOrCreateMacMini(): Device
    {
        $device = $this->em->getRepository(Device::class)->findOneBy(['hostname' => 'mac-mini']);

        if (!$device) {
            $device = new Device();
            $device->setName('Mac Mini M4');
            $device->setType('server');
            $device->setHostname('mac-mini');
            $device->setIpAddress('192.168.10.220');
            $device->setOs('macOS');
            $device->setIsMonitored(true);
            $this->em->persist($device);
            $this->em->flush();
            $this->logger->info('Device "Mac Mini M4" créé automatiquement.');
        }

        return $device;
    }
}
