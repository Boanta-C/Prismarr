<?php

namespace App\Controller;

use App\Entity\Infrastructure\Device;
use App\Entity\Infrastructure\Metric;
use App\Entity\Infrastructure\ServiceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page d'intégrité système : vue centralisée des supervisions de "santé"
 * (mounts NFS, intégrité SQLite des containers, nettoyage fichiers macOS).
 *
 * Distincte des pages Infrastructure (CPU/RAM/disque) et Docker (containers) —
 * concentre tout ce qui relève des checks périodiques de santé.
 */
#[IsGranted('ROLE_USER')]
#[Route('/integrite', name: 'app_integrity')]
class IntegrityController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: '')]
    public function index(): Response
    {
        $macDevice = $this->em->getRepository(Device::class)
            ->findOneBy(['hostname' => 'mac-mini']);

        $mountServices = [];
        $integrityChecks = [];
        $cleanupRun = null;
        $cleanupHistory = [];
        $mountLog = [
            'last_exit'   => null,
            'last_failed' => null,
            'checked_at'  => null,
        ];

        if ($macDevice) {
            // --- Mounts NFS ---
            $mountStatuses = $this->em->createQueryBuilder()
                ->select('s')
                ->from(ServiceStatus::class, 's')
                ->where('s.device = :device AND s.name LIKE :p')
                ->setParameter('device', $macDevice)
                ->setParameter('p', 'mount.%')
                ->orderBy('s.name', 'ASC')
                ->getQuery()->getResult();

            foreach ($mountStatuses as $svc) {
                $name  = $svc->getName();
                $short = substr($name, 6);
                $mountServices[] = [
                    'service'      => $svc,
                    'short'        => $short,
                    'size_gb'      => $this->getLatestMetric($macDevice, "{$name}.size_gb")?->getValue(),
                    'free_gb'      => $this->getLatestMetric($macDevice, "{$name}.free_gb")?->getValue(),
                    'used_percent' => $this->getLatestMetric($macDevice, "{$name}.used_percent")?->getValue(),
                ];
            }

            $mountLog = [
                'last_exit'   => $this->getLatestMetric($macDevice, 'system.mount_nas.last_exit')?->getValue(),
                'last_failed' => $this->getLatestMetric($macDevice, 'system.mount_nas.last_failed')?->getValue(),
                'checked_at'  => $this->getLatestMetric($macDevice, 'system.mount_nas.last_failed')?->getRecordedAt(),
            ];

            // --- Intégrité SQLite ---
            $integrityStatuses = $this->em->createQueryBuilder()
                ->select('s')
                ->from(ServiceStatus::class, 's')
                ->where('s.device = :device AND s.name LIKE :p')
                ->setParameter('device', $macDevice)
                ->setParameter('p', 'db_integrity.%')
                ->orderBy('s.name', 'ASC')
                ->getQuery()->getResult();

            foreach ($integrityStatuses as $svc) {
                $name  = $svc->getName();
                $label = substr($name, strlen('db_integrity.'));
                $integrityChecks[] = [
                    'label'   => $label,
                    'service' => $svc,
                    'size_mb' => $this->getLatestMetric($macDevice, "{$name}.size_mb")?->getValue(),
                ];
            }

            // --- Cleanup fichiers macOS ---
            $cleanupSvc = $this->em->getRepository(ServiceStatus::class)
                ->findOneBy(['device' => $macDevice, 'name' => 'cleanup.mac_crap']);

            if ($cleanupSvc) {
                $details = $cleanupSvc->getDetails();
                $report  = $details ? json_decode($details, true) : null;
                $cleanupRun = [
                    'service' => $cleanupSvc,
                    'report'  => $report,
                    'last_total_files' => $this->getLatestMetric($macDevice, 'cleanup.mac_crap.total_files')?->getValue(),
                    'last_duration_ms' => $this->getLatestMetric($macDevice, 'cleanup.mac_crap.duration_ms')?->getValue(),
                ];
            }

            // Historique 7 jours pour sparkline
            $cutoff = new \DateTimeImmutable('-7 days');
            $history = $this->em->createQueryBuilder()
                ->select('m.value', 'm.recordedAt')
                ->from(Metric::class, 'm')
                ->where('m.device = :device')
                ->andWhere('m.name = :name')
                ->andWhere('m.recordedAt >= :cutoff')
                ->setParameter('device', $macDevice)
                ->setParameter('name', 'cleanup.mac_crap.total_files')
                ->setParameter('cutoff', $cutoff)
                ->orderBy('m.recordedAt', 'ASC')
                ->getQuery()->getArrayResult();

            foreach ($history as $h) {
                $cleanupHistory[] = [
                    'date'  => $h['recordedAt']->format('d/m'),
                    'value' => (int) $h['value'],
                ];
            }
        }

        return $this->render('integrity/index.html.twig', [
            'device'           => $macDevice,
            'mount_services'   => $mountServices,
            'mount_log'        => $mountLog,
            'integrity_checks' => $integrityChecks,
            'cleanup_run'      => $cleanupRun,
            'cleanup_history'  => $cleanupHistory,
        ]);
    }

    private function getLatestMetric(Device $device, string $name): ?Metric
    {
        return $this->em->getRepository(Metric::class)->createQueryBuilder('m')
            ->where('m.device = :device')
            ->andWhere('m.name = :name')
            ->setParameter('device', $device)
            ->setParameter('name', $name)
            ->orderBy('m.recordedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
