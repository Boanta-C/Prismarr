<?php

namespace App\MessageHandler;

use App\Entity\Infrastructure\Device;
use App\Entity\Infrastructure\Metric;
use App\Entity\Infrastructure\ServiceStatus;
use App\Message\CleanMacCrapMessage;
use App\Service\Mac\MacAgentClient;
use App\Service\WorkerHealthService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Supprime les fichiers parasites macOS (._* AppleDouble + .DS_Store) sur les
 * 5 mounts NFS via l'endpoint /mounts/clean-crap de host-manager.
 *
 * Les metrics `cleanup.%` sont exemptées de la purge 24h de DbMaintenanceHandler
 * (rétention 30 jours) pour alimenter une sparkline d'historique.
 *
 * Déclenché :
 *  - Quotidiennement à 3h30 via ArgosSchedule (cron '30 3 * * *')
 *  - Manuellement via le bouton de la page Tâches (`POST /taches/run/clean_crap`)
 */
#[AsMessageHandler]
class CleanMacCrapHandler
{
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

    public function __invoke(CleanMacCrapMessage $message): void
    {
        $this->resetEmIfClosed();

        try {
            $data = $this->agent->cleanMacCrap();
            if ($data === null) {
                $this->logger->warning('CleanMacCrapHandler : agent host-manager injoignable.');
                $this->health->reportFailure('worker:clean_crap', 'mac-mini', 'Agent host-manager injoignable');
                return;
            }

            $device = $this->getDevice();

            $total       = (int)   ($data['total'] ?? 0);
            $durationMs  = (int)   ($data['duration_ms'] ?? 0);
            $cleanedMap  = (array) ($data['cleaned'] ?? []);
            $skipped     = (array) ($data['skipped'] ?? []);
            $errors      = (array) ($data['errors'] ?? []);

            // Metric agrégé — sert la sparkline page Tâches (rétention 30j)
            $m = new Metric();
            $m->setDevice($device)->setName('cleanup.mac_crap.total_files')->setValue((float) $total)->setUnit('');
            $this->em->persist($m);
            $m = new Metric();
            $m->setDevice($device)->setName('cleanup.mac_crap.duration_ms')->setValue((float) $durationMs)->setUnit('ms');
            $this->em->persist($m);

            // Ventilation par mount
            foreach ($cleanedMap as $name => $count) {
                $safe = preg_replace('/[^a-z0-9_-]+/i', '_', (string) $name);
                $m = new Metric();
                $m->setDevice($device)
                  ->setName("cleanup.mac_crap.by_mount.{$safe}")
                  ->setValue((float) $count)
                  ->setUnit('');
                $this->em->persist($m);
            }

            // ServiceStatus : rapport JSON complet dans details
            $svc = $this->getOrCreateService($device, 'cleanup.mac_crap');
            $svc->setStatus(empty($errors) ? 'up' : 'degraded');
            $svc->setCheckedAt(new \DateTimeImmutable());
            $svc->setResponseTimeMs($durationMs);
            $svc->setHttpCode(null);
            $svc->setUrl(null);
            $svc->setDetails(json_encode([
                'total'    => $total,
                'cleaned'  => $cleanedMap,
                'skipped'  => $skipped,
                'errors'   => $errors,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $this->em->flush();

            $this->publishToMercure($data);
            $this->health->reportSuccess('worker:clean_crap');

            $this->logger->info(sprintf(
                'CleanMacCrapHandler : %d fichier(s) supprimé(s) en %dms, %d mount(s) skippé(s), %d erreur(s).',
                $total, $durationMs, count($skipped), count($errors),
            ));
        } catch (\Throwable $e) {
            $this->logger->error('CleanMacCrapHandler : ' . $e->getMessage());
            $this->health->reportFailure('worker:clean_crap', 'mac-mini', 'Cleanup en erreur : ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------

    private function publishToMercure(array $data): void
    {
        try {
            $this->hub->publish(new Update('/argos/cleanup', json_encode([
                'total'       => $data['total'] ?? 0,
                'duration_ms' => $data['duration_ms'] ?? 0,
                'cleaned'     => $data['cleaned'] ?? [],
                'last_seen'   => date('H:i:s'),
            ])));
        } catch (\Throwable $e) {
            $this->logger->warning('CleanMacCrapHandler : Mercure publish failed — ' . $e->getMessage());
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
