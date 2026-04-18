<?php

namespace App\MessageHandler;

use App\Entity\Infrastructure\Alert;
use App\Entity\Infrastructure\Device;
use App\Entity\Infrastructure\Metric;
use App\Entity\Infrastructure\ServiceStatus;
use App\Message\CheckDbIntegrityMessage;
use App\Repository\Infrastructure\AlertRepository;
use App\Service\Mac\MacAgentClient;
use App\Service\WorkerHealthService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Vérifie l'intégrité SQLite des containers critiques via PRAGMA integrity_check.
 *
 * Passe par host-manager (docker cp + container Alpine sidecar) pour éviter
 * tout lock SQLite sur la DB live. Résultat stocké en ServiceStatus (prefix
 * `db_integrity.<label>`) avec le rapport complet dans `details`.
 *
 * Déclenchée :
 *  - Quotidiennement à 3h via ArgosSchedule (cron '0 3 * * *')
 *  - Manuellement via le bouton de la page Tâches (`POST /taches/run/db_integrity`)
 */
#[AsMessageHandler]
class CheckDbIntegrityHandler
{
    /**
     * Liste hardcodée des DB à checker (sync avec la whitelist côté host-manager).
     * L'ordre est conservé pour l'affichage UI.
     */
    private const TARGETS = [
        ['container' => 'Jellyfin',   'db_path' => '/config/data/jellyfin.db', 'label' => 'jellyfin'],
        ['container' => 'Jellyseerr', 'db_path' => '/app/config/db/db.sqlite3', 'label' => 'jellyseerr'],
        ['container' => 'Radarr',     'db_path' => '/config/radarr.db',         'label' => 'radarr'],
        ['container' => 'Sonarr',     'db_path' => '/config/sonarr.db',         'label' => 'sonarr'],
        ['container' => 'Prowlarr',   'db_path' => '/config/prowlarr.db',       'label' => 'prowlarr'],
    ];

    private EntityManagerInterface $em;

    public function __construct(
        private readonly MacAgentClient      $agent,
        private readonly ManagerRegistry     $doctrine,
        private readonly AlertRepository     $alertRepo,
        private readonly LoggerInterface     $logger,
        private readonly HubInterface        $hub,
        private readonly WorkerHealthService $health,
    ) {
        $this->em = $doctrine->getManager();
    }

    public function __invoke(CheckDbIntegrityMessage $message): void
    {
        $this->resetEmIfClosed();

        try {
            $data = $this->agent->runDbIntegrity(self::TARGETS);
            if ($data === null || empty($data['checks'])) {
                $this->logger->warning('CheckDbIntegrityHandler : agent injoignable ou réponse vide.');
                $this->health->reportFailure('worker:db_integrity', 'mac-mini', 'Agent host-manager injoignable');
                return;
            }

            $device = $this->getDevice();
            $corruptedLabels = [];

            foreach ($data['checks'] as $check) {
                $label = $check['label'] ?? 'unknown';
                $name  = 'db_integrity.' . $label;
                $svc   = $this->getOrCreateService($device, $name);

                $result = $check['result'] ?? 'error';
                $report = $check['report'] ?? '';

                // Mapping result → status ServiceStatus
                $status = match ($result) {
                    'ok'        => 'up',
                    'corrupted' => 'error',
                    default     => 'degraded',
                };
                $svc->setStatus($status);
                $svc->setCheckedAt(new \DateTimeImmutable());
                $svc->setResponseTimeMs($check['duration_ms'] ?? null);
                $svc->setDetails($report);
                $svc->setUrl($check['container'] ?? null);
                $svc->setHttpCode(null);

                // Metric size_mb pour historique éventuel
                if (!empty($check['size_bytes'])) {
                    $m = new Metric();
                    $m->setDevice($device)
                      ->setName("{$name}.size_mb")
                      ->setValue(round($check['size_bytes'] / 1024 / 1024, 1))
                      ->setUnit('MB');
                    $this->em->persist($m);
                }

                if ($result === 'corrupted') {
                    $corruptedLabels[] = $label;
                }
            }

            // Trace le dernier run pour la page Tâches
            $m = new Metric();
            $m->setDevice($device)->setName('system.db_integrity.last_run')->setValue(1.0)->setUnit('');
            $this->em->persist($m);

            $this->em->flush();

            // Alerte critique par DB corrompue
            $this->upsertAlerts($device, $corruptedLabels);
            $this->em->flush();

            $this->publishToMercure($data['checks']);
            $this->health->reportSuccess('worker:db_integrity');

            $this->logger->info(sprintf(
                'CheckDbIntegrityHandler : %d DB vérifiées, %d corrompue(s).',
                count($data['checks']),
                count($corruptedLabels),
            ));
        } catch (\Throwable $e) {
            $this->logger->error('CheckDbIntegrityHandler : ' . $e->getMessage());
            $this->health->reportFailure('worker:db_integrity', 'mac-mini', 'DB integrity en erreur : ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------

    private function upsertAlerts(Device $device, array $corruptedLabels): void
    {
        foreach ($corruptedLabels as $label) {
            $source = 'db_integrity:' . $label;
            if ($this->alertRepo->findActiveByDeviceAndSource($device, $source) === null) {
                $alert = (new Alert())
                    ->setDevice($device)
                    ->setSeverity('critical')
                    ->setSource($source)
                    ->setMessage(sprintf('Base SQLite corrompue sur %s', $label));
                $this->em->persist($alert);
            }
        }

        // Résolution auto si la DB redevient saine
        $active = $this->alertRepo->findActiveByDeviceAndSourcePrefix($device, 'db_integrity:');
        foreach ($active as $alert) {
            $label = substr($alert->getSource(), strlen('db_integrity:'));
            if (!in_array($label, $corruptedLabels, true)) {
                $alert->setResolvedAt(new \DateTimeImmutable());
            }
        }
    }

    private function publishToMercure(array $checks): void
    {
        try {
            $this->hub->publish(new Update('/argos/db-integrity', json_encode([
                'checks'    => $checks,
                'last_seen' => date('H:i:s'),
            ])));
        } catch (\Throwable $e) {
            $this->logger->warning('CheckDbIntegrityHandler : Mercure publish failed — ' . $e->getMessage());
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
