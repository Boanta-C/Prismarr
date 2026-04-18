<?php

namespace App\Service;

use App\Entity\Infrastructure\Alert;
use App\Entity\Infrastructure\Device;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Signale dans l'app (via Alert) quand un worker handler échoue ou récupère.
 * Sources : worker:docker, worker:synology, worker:unifi, worker:db_maintenance.
 * Tout est enveloppé dans try/catch — si la BDD est KO, on log sans crasher.
 *
 * Une alerte n'est créée qu'après FAILURE_THRESHOLD échecs consécutifs,
 * pour éviter les faux positifs sur des erreurs transitoires (ex: UniFi 429).
 */
class WorkerHealthService
{
    private const FAILURE_THRESHOLD = 3;

    /** Compteur d'échecs consécutifs par source — réinitialisé au redémarrage du worker. */
    private static array $failureCount = [];

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Incrémente le compteur d'échecs. Crée l'alerte uniquement après FAILURE_THRESHOLD échecs consécutifs.
     * Appelé quand un handler rencontre une erreur critique.
     */
    public function reportFailure(string $source, string $deviceHostname, string $message): void
    {
        self::$failureCount[$source] = (self::$failureCount[$source] ?? 0) + 1;

        if (self::$failureCount[$source] < self::FAILURE_THRESHOLD) {
            $this->logger->warning(sprintf(
                "[WorkerHealth] Échec %d/%d — %s: %s",
                self::$failureCount[$source], self::FAILURE_THRESHOLD, $source, $message
            ));
            return;
        }

        try {
            $em = $this->getEm();

            $existing = $this->findActiveAlert($em, $source);
            if ($existing !== null) {
                return; // alerte déjà ouverte, pas de doublon
            }

            $device = $em->getRepository(Device::class)->findOneBy(['hostname' => $deviceHostname]);

            $alert = (new Alert())
                ->setDevice($device)
                ->setSeverity('warning')
                ->setSource($source)
                ->setMessage($message);

            $em->persist($alert);
            $em->flush();

            $this->logger->warning("[WorkerHealth] Alerte créée après {self::FAILURE_THRESHOLD} échecs — {$source}: {$message}");
        } catch (\Throwable $e) {
            // BDD toujours KO — on ne peut pas créer l'alerte, on log seulement
            $this->logger->error("[WorkerHealth] Impossible de créer l'alerte {$source}: {$e->getMessage()}");
        }
    }

    /**
     * Réinitialise le compteur d'échecs et résout l'alerte si elle existe.
     * Appelé quand un handler réussit après avoir été en erreur.
     */
    public function reportSuccess(string $source): void
    {
        self::$failureCount[$source] = 0;

        try {
            $em    = $this->getEm();
            $alert = $this->findActiveAlert($em, $source);

            if ($alert === null) {
                return; // pas d'alerte active, rien à faire
            }

            $alert->setResolvedAt(new \DateTimeImmutable());
            $em->flush();

            $this->logger->info("[WorkerHealth] Alerte résolue — {$source}");
        } catch (\Throwable $e) {
            $this->logger->warning("[WorkerHealth] Impossible de résoudre l'alerte {$source}: {$e->getMessage()}");
        }
    }

    private function findActiveAlert(EntityManagerInterface $em, string $source): ?Alert
    {
        return $em->createQueryBuilder()
            ->select('a')
            ->from(Alert::class, 'a')
            ->where('a.source = :source')
            ->andWhere('a.resolvedAt IS NULL')
            ->setParameter('source', $source)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function getEm(): EntityManagerInterface
    {
        $em = $this->doctrine->getManager();
        if (!$em->isOpen()) {
            $this->doctrine->resetManager();
            $em = $this->doctrine->getManager();
        }
        return $em;
    }
}
