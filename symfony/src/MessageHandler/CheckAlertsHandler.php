<?php

namespace App\MessageHandler;

use App\Entity\Infrastructure\Alert;
use App\Entity\Infrastructure\Device;
use App\Entity\Infrastructure\Metric;
use App\Entity\Infrastructure\ServiceStatus;
use App\Message\CheckAlertsMessage;
use App\Repository\Infrastructure\AlertRepository;
use App\Service\Media\RadarrClient;
use App\Service\WorkerHealthService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class CheckAlertsHandler
{
    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry     $doctrine,
        private readonly AlertRepository     $alertRepo,
        private readonly HubInterface        $hub,
        private readonly WorkerHealthService $health,
        private readonly RadarrClient        $radarr,
    ) {
        $this->em = $doctrine->getManager();
    }

    public function __invoke(CheckAlertsMessage $message): void
    {
        $this->resetEmIfClosed();

        try {
            $server = $this->em->getRepository(Device::class)->findOneBy(['hostname' => 'mac-mini']);
            $nas    = $this->em->getRepository(Device::class)->findOneBy(['hostname' => 'ds-medusa']);

            if ($server) {
                $this->checkContainersDown($server);
                $this->checkMountsDown($server);
                $this->checkMacAgentOffline($server);
                $this->checkRadarrHealth($server);
            }
            if ($nas) {
                $this->checkNasDiskUsage($nas);
                $this->checkNasTemperature($nas);
            }

            $this->em->flush();

            try {
                $this->hub->publish(new Update(
                    '/argos/alerts',
                    json_encode([
                        'count'    => $this->alertRepo->countActive(),
                        'critical' => $this->alertRepo->countActiveBySeverity('critical'),
                        'warning'  => $this->alertRepo->countActiveBySeverity('warning'),
                    ])
                ));
            } catch (\Throwable) {}

            // Trace le dernier run pour la page Tâches
            if ($server) {
                $m = new Metric();
                $m->setDevice($server)->setName('system.check_alerts.last_run')->setValue(1.0)->setUnit('');
                $this->em->persist($m);
                $this->em->flush();
            }

            $this->health->reportSuccess('worker:check_alerts');
        } catch (\Throwable $e) {
            $this->health->reportFailure('worker:check_alerts', 'mac-mini', 'Worker CheckAlerts en erreur : ' . $e->getMessage());
        }
    }

    private function resetEmIfClosed(): void
    {
        if (!$this->em->isOpen()) {
            $this->doctrine->resetManager();
            $this->em = $this->doctrine->getManager();
        }
    }

    private function checkContainersDown(Device $server): void
    {
        $downStatuses = $this->em->getRepository(ServiceStatus::class)
            ->findBy(['device' => $server, 'status' => 'down']);

        // Exclure les mounts NFS (gérés par checkMountsDown avec source dédiée)
        $downStatuses = array_filter($downStatuses, fn($s) => !str_starts_with($s->getName(), 'mount.'));
        $downNames = array_map(fn($s) => $s->getName(), $downStatuses);

        foreach ($downStatuses as $status) {
            $source = substr('container:' . $status->getName(), 0, 50);
            if ($this->alertRepo->findActiveByDeviceAndSource($server, $source) === null) {
                $alert = (new Alert())
                    ->setDevice($server)
                    ->setSeverity('critical')
                    ->setSource($source)
                    ->setMessage(sprintf('Container "%s" est arrêté', $status->getName()));
                $this->em->persist($alert);
            }
        }

        $activeContainerAlerts = $this->alertRepo->findActiveByDeviceAndSourcePrefix($server, 'container:');
        foreach ($activeContainerAlerts as $alert) {
            $containerName = substr($alert->getSource(), strlen('container:'));
            if (!in_array($containerName, $downNames, true)) {
                $alert->setResolvedAt(new \DateTimeImmutable());
            }
        }
    }

    private function checkMountsDown(Device $server): void
    {
        $allMounts = $this->em->getRepository(ServiceStatus::class)
            ->createQueryBuilder('s')
            ->where('s.device = :device')
            ->andWhere('s.name LIKE :prefix')
            ->setParameter('device', $server)
            ->setParameter('prefix', 'mount.%')
            ->getQuery()
            ->getResult();

        $downMounts = array_filter($allMounts, fn($s) => $s->getStatus() === 'down');
        $downNames  = array_map(fn($s) => $s->getName(), $downMounts);

        foreach ($downMounts as $status) {
            $source = 'mount:' . $status->getName();
            if ($this->alertRepo->findActiveByDeviceAndSource($server, $source) === null) {
                $short = substr($status->getName(), 6); // strip "mount."
                $alert = (new Alert())
                    ->setDevice($server)
                    ->setSeverity('warning')
                    ->setSource($source)
                    ->setMessage(sprintf('Mount NFS "%s" est hors-ligne', $short));
                $this->em->persist($alert);
            }
        }

        // Résolution auto si le mount est revenu
        $activeMountAlerts = $this->alertRepo->findActiveByDeviceAndSourcePrefix($server, 'mount:');
        foreach ($activeMountAlerts as $alert) {
            $mountName = substr($alert->getSource(), strlen('mount:'));
            if (!in_array($mountName, $downNames, true)) {
                $alert->setResolvedAt(new \DateTimeImmutable());
            }
        }
    }

    private function checkNasDiskUsage(Device $nas): void
    {
        $rows = $this->em->createQuery(
            "SELECT DISTINCT m.name FROM App\\Entity\\Infrastructure\\Metric m
             WHERE m.device = :nas AND m.name LIKE 'synology.%.used_percent'
             AND m.recordedAt > :cutoff"
        )
            ->setParameter('nas', $nas)
            ->setParameter('cutoff', new \DateTimeImmutable('-2 hours'))
            ->getArrayResult();

        $volumeMetricNames = array_column($rows, 'name');
        $checkedSources    = [];

        foreach ($volumeMetricNames as $metricName) {
            $parts = explode('.', $metricName);
            $volId = $parts[1] ?? null;
            if (!$volId) {
                continue;
            }

            $value = $this->latestMetricValue($nas, $metricName);
            if ($value === null) {
                continue;
            }

            $source           = 'disk:' . $volId;
            $checkedSources[] = $source;
            $existing         = $this->alertRepo->findActiveByDeviceAndSource($nas, $source);

            if ($value >= 90.0) {
                $severity = 'critical';
                $message  = sprintf('Disque %s : %.1f%% utilisé (critique)', $volId, $value);
            } elseif ($value >= 80.0) {
                $severity = 'warning';
                $message  = sprintf('Disque %s : %.1f%% utilisé (avertissement)', $volId, $value);
            } else {
                $severity = null;
                $message  = null;
            }

            if ($severity !== null && $existing === null) {
                $this->em->persist(
                    (new Alert())->setDevice($nas)->setSeverity($severity)->setSource($source)->setMessage($message)
                );
            } elseif ($severity === null && $existing !== null) {
                $existing->setResolvedAt(new \DateTimeImmutable());
            } elseif ($severity !== null && $existing !== null && $existing->getSeverity() !== $severity) {
                $existing->setSeverity($severity)->setMessage($message);
            }
        }

        $activeDiskAlerts = $this->alertRepo->findActiveByDeviceAndSourcePrefix($nas, 'disk:');
        foreach ($activeDiskAlerts as $alert) {
            if (!in_array($alert->getSource(), $checkedSources, true)) {
                $alert->setResolvedAt(new \DateTimeImmutable());
            }
        }
    }

    private function checkNasTemperature(Device $nas): void
    {
        $temp     = $this->latestMetricValue($nas, 'synology.temp');
        $existing = $this->alertRepo->findActiveByDeviceAndSource($nas, 'temperature');

        if ($temp !== null && $temp >= 75.0) {
            $severity = 'critical';
            $message  = sprintf('Température NAS critique : %.1f°C', $temp);
        } elseif ($temp !== null && $temp >= 70.0) {
            $severity = 'warning';
            $message  = sprintf('Température NAS élevée : %.1f°C', $temp);
        } else {
            $severity = null;
            $message  = null;
        }

        if ($severity !== null && $existing === null) {
            $this->em->persist(
                (new Alert())->setDevice($nas)->setSeverity($severity)->setSource('temperature')->setMessage($message)
            );
        } elseif ($severity === null && $existing !== null) {
            $existing->setResolvedAt(new \DateTimeImmutable());
        } elseif ($severity !== null && $existing !== null && $existing->getSeverity() !== $severity) {
            $existing->setSeverity($severity)->setMessage($message);
        }
    }

    private function checkMacAgentOffline(Device $server): void
    {
        $agentActive = $this->latestMetricValue($server, 'mac.agent_active');
        $existing    = $this->alertRepo->findActiveByDeviceAndSource($server, 'mac.agent_offline');

        // Pas encore de métrique (agent jamais démarré) : pas d'alerte
        if ($agentActive === null) {
            return;
        }

        if ($agentActive < 1.0 && $existing === null) {
            $this->em->persist(
                (new Alert())
                    ->setDevice($server)
                    ->setSeverity('warning')
                    ->setSource('mac.agent_offline')
                    ->setMessage('Agent Python Mac Mini arrêté — métriques macOS indisponibles')
            );
        } elseif ($agentActive >= 1.0 && $existing !== null) {
            $existing->setResolvedAt(new \DateTimeImmutable());
        }
    }

    private function checkRadarrHealth(Device $server): void
    {
        try {
            $healthItems = $this->radarr->getSystemHealth();
        } catch (\Throwable) {
            // Radarr inaccessible — créer une alerte
            $source = 'radarr:offline';
            if ($this->alertRepo->findActiveByDeviceAndSource($server, $source) === null) {
                $this->em->persist(
                    (new Alert())
                        ->setDevice($server)
                        ->setSeverity('critical')
                        ->setSource($source)
                        ->setMessage('Radarr est inaccessible')
                );
            }
            return;
        }

        // Radarr accessible — résoudre l'alerte offline si elle existe
        $offlineAlert = $this->alertRepo->findActiveByDeviceAndSource($server, 'radarr:offline');
        if ($offlineAlert) {
            $offlineAlert->setResolvedAt(new \DateTimeImmutable());
        }

        // Traiter chaque alerte santé Radarr
        $activeSources = [];
        foreach ($healthItems as $h) {
            $hSource = 'radarr:' . ($h['source'] ?? 'unknown');
            $hSource = substr($hSource, 0, 50);
            $activeSources[] = $hSource;
            $message = ($h['message'] ?? 'Problème Radarr');
            $severity = ($h['type'] ?? '') === 'error' ? 'critical' : 'warning';

            $existing = $this->alertRepo->findActiveByDeviceAndSource($server, $hSource);
            if ($existing === null) {
                $this->em->persist(
                    (new Alert())
                        ->setDevice($server)
                        ->setSeverity($severity)
                        ->setSource($hSource)
                        ->setMessage('Radarr : ' . $message)
                );
            } elseif ($existing->getMessage() !== 'Radarr : ' . $message) {
                $existing->setMessage('Radarr : ' . $message)->setSeverity($severity);
            }
        }

        // Vérifier les imports bloqués dans la queue
        try {
            $queue = $this->radarr->getQueue();
            $blocked = array_filter($queue, fn($q) => ($q['trackedState'] ?? '') === 'importBlocked');
            $source = 'radarr:import_blocked';
            $activeSources[] = $source;
            $existing = $this->alertRepo->findActiveByDeviceAndSource($server, $source);

            if (count($blocked) > 0) {
                $names = array_map(fn($q) => $q['title'] ?? '?', $blocked);
                $msg = count($blocked) . ' import(s) bloqué(s) : ' . implode(', ', array_slice($names, 0, 3));
                if ($existing === null) {
                    $this->em->persist(
                        (new Alert())->setDevice($server)->setSeverity('warning')->setSource($source)->setMessage($msg)
                    );
                } else {
                    $existing->setMessage($msg);
                }
            } elseif ($existing !== null) {
                $existing->setResolvedAt(new \DateTimeImmutable());
            }
        } catch (\Throwable) {}

        // Résoudre les alertes Radarr qui ne sont plus remontées
        $activeRadarrAlerts = $this->alertRepo->findActiveByDeviceAndSourcePrefix($server, 'radarr:');
        foreach ($activeRadarrAlerts as $alert) {
            if ($alert->getSource() === 'radarr:offline') continue;
            if ($alert->getSource() === 'radarr:import_blocked') continue;
            if (!in_array($alert->getSource(), $activeSources, true)) {
                $alert->setResolvedAt(new \DateTimeImmutable());
            }
        }
    }

    private function latestMetricValue(Device $device, string $name): ?float
    {
        $metric = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Metric::class, 'm')
            ->where('m.device = :device')
            ->andWhere('m.name = :name')
            ->setParameter('device', $device)
            ->setParameter('name', $name)
            ->orderBy('m.recordedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $metric?->getValue();
    }
}
