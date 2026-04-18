<?php

namespace App\Controller;

use App\Entity\Infrastructure\Alert;
use App\Entity\Infrastructure\Device;
use App\Entity\Infrastructure\Metric;
use App\Message\CheckAlertsMessage;
use App\Message\CheckDbIntegrityMessage;
use App\Message\CleanMacCrapMessage;
use App\Message\DbMaintenanceMessage;
use App\Message\PollDockerMessage;
use App\Message\PollMacMetricsMessage;
use App\Message\PollMountsMessage;
use App\Message\PollSynologyMessage;
use App\Message\PollUnifiMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[IsGranted('ROLE_USER')]
#[Route('/taches', name: 'app_tasks')]
class TasksController extends AbstractController
{
    private const JOB_MESSAGES = [
        'docker'       => PollDockerMessage::class,
        'mac'          => PollMacMetricsMessage::class,
        'synology'     => PollSynologyMessage::class,
        'unifi'        => PollUnifiMessage::class,
        'mounts'       => PollMountsMessage::class,
        'alerts'       => CheckAlertsMessage::class,
        'db_integrity' => CheckDbIntegrityMessage::class,
        'clean_crap'   => CleanMacCrapMessage::class,
        'maintenance'  => DbMaintenanceMessage::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface    $http,
        private readonly MessageBusInterface    $bus,
        private readonly string                 $hostManagerUrl,
        private readonly string                 $hostManagerToken,
        private readonly string                 $agentUrl,
    ) {}

    // ── Page principale ───────────────────────────────────────────────────────

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('tasks/index.html.twig', [
            'jobs'          => $this->buildJobsList(),
            'agent_status'  => $this->getAgentStatus(),
            'worker_alerts' => $this->getWorkerAlerts(),
            'manager_url'   => $this->hostManagerUrl,
        ]);
    }

    // ── Déclenchement manuel d'un job ─────────────────────────────────────────

    #[Route('/run/{job}', name: '_run', methods: ['POST'])]
    public function runJob(string $job, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('run_job', $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Token CSRF invalide'], 403);
        }
        if (!isset(self::JOB_MESSAGES[$job])) {
            return new JsonResponse(['error' => 'Job inconnu'], 400);
        }
        $class = self::JOB_MESSAGES[$job];
        $this->bus->dispatch(new $class());
        return new JsonResponse(['status' => 'Déclenché']);
    }

    // ── Actions agent (AJAX) ──────────────────────────────────────────────────

    #[Route('/agent/start', name: '_agent_start', methods: ['POST'])]
    public function agentStart(): JsonResponse
    {
        return $this->callManager('/agent/start');
    }

    #[Route('/agent/stop', name: '_agent_stop', methods: ['POST'])]
    public function agentStop(): JsonResponse
    {
        return $this->callManager('/agent/stop');
    }

    #[Route('/agent/restart', name: '_agent_restart', methods: ['POST'])]
    public function agentRestart(): JsonResponse
    {
        return $this->callManager('/agent/restart');
    }

    #[Route('/agent/status', name: '_agent_status', methods: ['GET'])]
    public function agentStatus(): JsonResponse
    {
        return new JsonResponse($this->getAgentStatus());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function callManager(string $path): JsonResponse
    {
        if ($this->hostManagerUrl === '') {
            return new JsonResponse(['error' => 'MAC_HOST_MANAGER_URL non configuré'], 503);
        }
        try {
            $response = $this->http->request('POST', rtrim($this->hostManagerUrl, '/') . $path, [
                'timeout' => 5,
                'headers' => ['X-Argos-Token' => $this->hostManagerToken],
            ]);
            return new JsonResponse($response->toArray(false), $response->getStatusCode());
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Host manager injoignable : ' . $e->getMessage()], 503);
        }
    }

    private function getAgentStatus(): array
    {
        if ($this->hostManagerUrl !== '') {
            try {
                $r    = $this->http->request('GET', rtrim($this->hostManagerUrl, '/') . '/agent/status', ['timeout' => 3]);
                $data = $r->toArray(false);
                return [
                    'running'        => $data['running'] ?? false,
                    'pid'            => $data['pid']     ?? null,
                    'manager_online' => true,
                    'agent_url'      => $this->agentUrl,
                ];
            } catch (\Throwable) {}
        }

        if ($this->agentUrl !== '') {
            try {
                $r    = $this->http->request('GET', rtrim($this->agentUrl, '/') . '/health', ['timeout' => 2]);
                $data = $r->toArray(false);
                return [
                    'running'        => ($data['status'] ?? '') === 'ok',
                    'pid'            => $data['pid'] ?? null,
                    'manager_online' => false,
                    'agent_url'      => $this->agentUrl,
                ];
            } catch (\Throwable) {}
        }

        return ['running' => false, 'pid' => null, 'manager_online' => false, 'agent_url' => $this->agentUrl];
    }

    private function buildJobsList(): array
    {
        $devices = $this->em->getRepository(Device::class)->findAll();
        $deviceMap = [];
        foreach ($devices as $d) {
            $deviceMap[$d->getHostname()] = $d;
        }
        $macMini = $deviceMap['mac-mini'] ?? null;

        $jobs = [
            [
                'key'         => 'docker',
                'name'        => 'Docker Polling',
                'description' => 'Containers Mac Mini — CPU, RAM, uptime, statut',
                'interval'    => '10s',
                'source'      => 'worker:docker',
                'last_run'    => $this->getLastMetricTime($macMini, 'docker.'),
            ],
            [
                'key'         => 'mac',
                'name'        => 'Mac Metrics',
                'description' => 'CPU, RAM, Disque, Réseau, Swap, Top processus',
                'interval'    => '10s',
                'source'      => 'worker:mac_metrics',
                'last_run'    => $this->getLastMetricTime($macMini, 'mac.cpu'),
            ],
            [
                'key'         => 'synology',
                'name'        => 'Synology NAS',
                'description' => 'CPU, RAM, Température, Volumes DS-MEDUSA',
                'interval'    => '30s',
                'source'      => 'worker:synology',
                'last_run'    => ($deviceMap['ds-medusa'] ?? null)?->getLastSeenAt(),
            ],
            [
                'key'         => 'unifi',
                'name'        => 'UniFi Express',
                'description' => 'Clients WiFi/filaires, health subsystems, AP stats',
                'interval'    => '60s',
                'source'      => 'worker:unifi',
                'last_run'    => ($deviceMap['unifi-ap'] ?? null)?->getLastSeenAt(),
            ],
            [
                'key'         => 'mounts',
                'name'        => 'Mounts NFS',
                'description' => '5 mounts NFS du Mac (films, séries, animés, downloads) — taille, free, statut up/down',
                'interval'    => '60s',
                'source'      => 'worker:mounts',
                'last_run'    => $this->getLastMetricTime($macMini, 'mount.films.free_gb'),
            ],
            [
                'key'         => 'db_integrity',
                'name'        => 'Intégrité SQLite',
                'description' => 'PRAGMA integrity_check sur Jellyfin, Jellyseerr, Radarr, Sonarr, Prowlarr',
                'interval'    => '24h (3h)',
                'source'      => 'worker:db_integrity',
                'last_run'    => $this->getLastMetricTime($macMini, 'system.db_integrity.last_run'),
            ],
            [
                'key'         => 'clean_crap',
                'name'        => 'Nettoyage fichiers macOS',
                'description' => 'Supprime ._* (AppleDouble) et .DS_Store sur les 5 mounts NFS',
                'interval'    => '24h (3h30)',
                'source'      => 'worker:clean_crap',
                'last_run'    => $this->getLastMetricTime($macMini, 'cleanup.mac_crap.total_files'),
            ],
            [
                'key'         => 'alerts',
                'name'        => 'Vérification alertes',
                'description' => 'Service down, disque > 80 %, température > 70 °C',
                'interval'    => '30s',
                'source'      => 'worker:check_alerts',
                'last_run'    => $this->getLastMetricTime($macMini, 'system.check_alerts.last_run'),
            ],
            [
                'key'         => 'maintenance',
                'name'        => 'Maintenance BDD',
                'description' => 'Purge métriques > 24h, ServiceStatus fantômes > 48h',
                'interval'    => '1h',
                'source'      => 'worker:db_maintenance',
                'last_run'    => $this->getLastMetricTime($macMini, 'system.db_maintenance.last_run'),
            ],
        ];

        $activeAlerts    = $this->getWorkerAlerts();
        $alertsBySource  = [];
        foreach ($activeAlerts as $a) {
            $alertsBySource[$a->getSource()] = $a;
        }

        foreach ($jobs as &$job) {
            $job['alert']  = $alertsBySource[$job['source']] ?? null;
            $job['status'] = $job['alert'] ? 'error' : ($job['last_run'] ? 'ok' : 'unknown');
        }

        return $jobs;
    }

    private function getLastMetricTime(?Device $device, string $namePrefix): ?\DateTimeImmutable
    {
        if (!$device) {
            return null;
        }

        $result = $this->em->createQueryBuilder()
            ->select('m.recordedAt')
            ->from(Metric::class, 'm')
            ->where('m.device = :device')
            ->andWhere('m.name LIKE :prefix')
            ->setParameter('device', $device)
            ->setParameter('prefix', $namePrefix . '%')
            ->orderBy('m.recordedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result ? $result['recordedAt'] : null;
    }

    private function getWorkerAlerts(): array
    {
        return $this->em->createQueryBuilder()
            ->select('a')
            ->from(Alert::class, 'a')
            ->where('a.source LIKE :pattern')
            ->andWhere('a.resolvedAt IS NULL')
            ->setParameter('pattern', 'worker:%')
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
