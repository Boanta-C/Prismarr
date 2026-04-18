<?php

namespace App\MessageHandler;

use App\Entity\Infrastructure\Device;
use App\Entity\Infrastructure\Metric;
use App\Message\PollMacMetricsMessage;
use App\Service\Mac\MacMetricsClient;
use App\Service\WorkerHealthService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class PollMacMetricsHandler
{
    /** Throttle BDD — une écriture toutes les 60s. */
    private ?\DateTimeImmutable $lastMetricFlushAt = null;

    private EntityManagerInterface $em;

    public function __construct(
        private readonly MacMetricsClient  $mac,
        private readonly ManagerRegistry   $doctrine,
        private readonly LoggerInterface   $logger,
        private readonly HubInterface      $hub,
        private readonly WorkerHealthService $health,
    ) {
        $this->em = $doctrine->getManager();
    }

    public function __invoke(PollMacMetricsMessage $message): void
    {
        $this->resetEmIfClosed();

        try {
            $metrics = $this->mac->getMetrics();
            $device  = $this->getDevice();

            $shouldPersist = $this->lastMetricFlushAt === null
                || $this->lastMetricFlushAt < new \DateTimeImmutable('-60 seconds');

            if ($shouldPersist) {
                $this->addMetric($device, 'mac.cpu',           $metrics['cpu'],           '%');
                $this->addMetric($device, 'mac.mem',           $metrics['mem_percent'],   '%');
                $this->addMetric($device, 'mac.mem_used_mb',   $metrics['mem_used_mb'],   'MB');
                $this->addMetric($device, 'mac.mem_total_mb',  $metrics['mem_total_mb'],  'MB');
                $this->addMetric($device, 'mac.disk',          $metrics['disk_percent'],  '%');
                $this->addMetric($device, 'mac.disk_used_gb',  $metrics['disk_used_gb'],  'GB');
                $this->addMetric($device, 'mac.disk_total_gb', $metrics['disk_total_gb'], 'GB');
                $this->addMetric($device, 'mac.uptime',        $metrics['uptime'],        's');
                if (!empty($metrics['load'])) {
                    $this->addMetric($device, 'mac.load_1',  $metrics['load'][0] ?? 0, '');
                    $this->addMetric($device, 'mac.load_5',  $metrics['load'][1] ?? 0, '');
                    $this->addMetric($device, 'mac.load_15', $metrics['load'][2] ?? 0, '');
                }
                $this->addMetric($device, 'mac.net_in_kbs',   $metrics['net_in_kbs'],   'KB/s');
                $this->addMetric($device, 'mac.net_out_kbs',  $metrics['net_out_kbs'],  'KB/s');
                $this->addMetric($device, 'mac.swap',         $metrics['swap_percent'],  '%');
                $this->addMetric($device, 'mac.swap_used_mb', $metrics['swap_used_mb'], 'MB');
                $this->addMetric($device, 'mac.disk_rw_mbs',  $metrics['disk_rw_mbs'],  'MB/s');
                if ($metrics['proc_count'] > 0) {
                    $this->addMetric($device, 'mac.proc_count', $metrics['proc_count'], '');
                }

                // Top processus — stockage individuel pour graphiques historiques
                foreach ($metrics['top_procs'] ?? [] as $proc) {
                    if (empty($proc['name']) || ($proc['cpu'] ?? 0) < 0.1) {
                        continue;
                    }
                    $safe = $this->sanitizeProcName($proc['name']);
                    $this->addMetric($device, "mac.proc.{$safe}.cpu", (float) $proc['cpu'], '%');
                    $this->addMetric($device, "mac.proc.{$safe}.mem", (float) $proc['mem'], '%');
                }

                $this->addMetric($device, 'mac.agent_active', ($metrics['agent_active'] ?? false) ? 1.0 : 0.0, '');
                $device->setLastSeenAt(new \DateTimeImmutable());
                $this->em->flush();
                $this->lastMetricFlushAt = new \DateTimeImmutable();
            }

            $this->publishToMercure($metrics);
        } catch (\Throwable $e) {
            $this->logger->error('PollMacMetricsHandler : ' . $e->getMessage());
            $this->health->reportFailure('worker:mac_metrics', 'mac-mini', 'Mac metrics en erreur : ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------

    private function publishToMercure(array $metrics): void
    {
        try {
            $this->hub->publish(new Update(
                '/argos/metrics/mac',
                json_encode(array_merge($metrics, ['last_seen' => date('H:i:s')]))
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('PollMacMetricsHandler : Mercure publish failed — ' . $e->getMessage());
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

    private function getDevice(): Device
    {
        $device = $this->em->getRepository(Device::class)->findOneBy(['hostname' => 'mac-mini']);

        if (!$device) {
            throw new \RuntimeException('Device mac-mini introuvable en base.');
        }

        return $device;
    }

    private function sanitizeProcName(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
    }

    private function resetEmIfClosed(): void
    {
        if (!$this->em->isOpen()) {
            $this->doctrine->resetManager();
            $this->em = $this->doctrine->getManager();
        }
    }
}
