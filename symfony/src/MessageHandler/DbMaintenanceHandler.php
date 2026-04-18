<?php

namespace App\MessageHandler;

use App\Entity\Infrastructure\Device;
use App\Entity\Infrastructure\Metric;
use App\Message\DbMaintenanceMessage;
use App\Service\WorkerHealthService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Maintenance BDD centralisée — tourne toutes les heures via Scheduler.
 * Ajouter ici toute nouvelle opération de nettoyage au fil des phases.
 * Toutes les opérations utilisent des DQL bulk DELETE (aucune entité chargée en mémoire).
 */
#[AsMessageHandler]
class DbMaintenanceHandler
{
    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry     $doctrine,
        private readonly LoggerInterface     $logger,
        private readonly WorkerHealthService $health,
    ) {
        $this->em = $doctrine->getManager();
    }

    public function __invoke(DbMaintenanceMessage $message): void
    {
        $this->resetEmIfClosed();

        try {
            $this->purgeOldMetrics();
            $this->purgeStaleServiceStatus();
            $this->purgeOldResolvedAlerts();
            $this->purgeOldAuditLogs();

            // Trace le dernier run pour la page Tâches
            $macMini = $this->em->getRepository(Device::class)->findOneBy(['hostname' => 'mac-mini']);
            if ($macMini) {
                $m = new Metric();
                $m->setDevice($macMini)->setName('system.db_maintenance.last_run')->setValue(1.0)->setUnit('');
                $this->em->persist($m);
                $this->em->flush();
            }

            $this->health->reportSuccess('worker:db_maintenance');
        } catch (\Throwable $e) {
            $this->logger->error('DbMaintenanceHandler : ' . $e->getMessage());
            $this->health->reportFailure('worker:db_maintenance', 'mac-mini', 'Maintenance BDD en erreur : ' . $e->getMessage());
        }
    }

    private function resetEmIfClosed(): void
    {
        if (!$this->em->isOpen()) {
            $this->doctrine->resetManager();
            $this->em = $this->doctrine->getManager();
        }
    }

    /**
     * Supprime les métriques de plus de 24h.
     * ~320 000 lignes/jour sans purge → plafonne à ~15 000 lignes avec.
     *
     * Exception : les metrics `cleanup.%` sont conservées 30 jours pour la
     * sparkline d'historique sur la page Tâches.
     */
    private function purgeOldMetrics(): void
    {
        $deleted = $this->em->createQuery(
            'DELETE FROM App\Entity\Infrastructure\Metric m
             WHERE m.recordedAt < :cutoff AND m.name NOT LIKE :keep'
        )
            ->setParameter('cutoff', new \DateTimeImmutable('-24 hours'))
            ->setParameter('keep', 'cleanup.%')
            ->execute();

        $deletedLong = $this->em->createQuery(
            'DELETE FROM App\Entity\Infrastructure\Metric m
             WHERE m.recordedAt < :cutoff AND m.name LIKE :keep'
        )
            ->setParameter('cutoff', new \DateTimeImmutable('-30 days'))
            ->setParameter('keep', 'cleanup.%')
            ->execute();

        $this->logger->info(sprintf(
            '[DbMaintenance] Métriques supprimées : %d (> 24h courantes) + %d (> 30j cleanup)',
            $deleted, $deletedLong,
        ));
    }

    /**
     * Supprime les ServiceStatus non mis à jour depuis plus de 48h.
     * Élimine les fantômes (volumes renommés, containers supprimés sans cleanup en temps réel).
     * Seuil 48h (et non 1h) pour éviter les faux positifs si le polling est temporairement KO.
     */
    private function purgeStaleServiceStatus(): void
    {
        $deleted = $this->em->createQuery(
            'DELETE FROM App\Entity\Infrastructure\ServiceStatus s WHERE s.checkedAt < :cutoff'
        )
            ->setParameter('cutoff', new \DateTimeImmutable('-48 hours'))
            ->execute();

        if ($deleted > 0) {
            $this->logger->info(sprintf('[DbMaintenance] ServiceStatus fantômes supprimés (> 48h) : %d', $deleted));
        }
    }

    /**
     * Supprime les entrées d'audit log de plus de 90 jours.
     */
    private function purgeOldAuditLogs(): void
    {
        $deleted = $this->em->createQuery(
            'DELETE FROM App\Entity\Admin\AuditLog l WHERE l.createdAt < :cutoff'
        )
            ->setParameter('cutoff', new \DateTimeImmutable('-90 days'))
            ->execute();

        if ($deleted > 0) {
            $this->logger->info(sprintf('[DbMaintenance] AuditLogs supprimés (> 90j) : %d', $deleted));
        }
    }

    /**
     * Supprime les alertes résolues de plus de 7 jours.
     */
    private function purgeOldResolvedAlerts(): void
    {
        $deleted = $this->em->createQuery(
            'DELETE FROM App\Entity\Infrastructure\Alert a
             WHERE a.resolvedAt IS NOT NULL AND a.resolvedAt < :cutoff'
        )
            ->setParameter('cutoff', new \DateTimeImmutable('-7 days'))
            ->execute();

        if ($deleted > 0) {
            $this->logger->info(sprintf('[DbMaintenance] Alertes résolues supprimées (> 7j) : %d', $deleted));
        }
    }
}
