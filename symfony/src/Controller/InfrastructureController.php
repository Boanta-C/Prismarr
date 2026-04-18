<?php

namespace App\Controller;

use App\Entity\Infrastructure\Device;
use App\Entity\Infrastructure\Metric;
use App\Entity\Infrastructure\ServiceStatus;
use App\Service\Synology\SynologyClient;
use App\Service\UniFi\UniFiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/infrastructure', name: 'app_infrastructure_')]
class InfrastructureController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SynologyClient         $synology,
        private readonly UniFiClient            $unifi,
    ) {}

    /**
     * Vue unifiée Docker : containers Mac Mini (SSE) + containers NAS (AJAX live).
     */
    #[Route('/docker', name: 'docker')]
    public function docker(): Response
    {
        $macDevice = $this->em->getRepository(Device::class)
            ->findOneBy(['hostname' => 'mac-mini']);

        $containers = [];
        if ($macDevice) {
            $services = $this->em->getRepository(ServiceStatus::class)
                ->findBy(['device' => $macDevice], ['name' => 'ASC']);

            foreach ($services as $service) {
                $name = $service->getName();
                // Skip ServiceStatus d'infra (page Intégrité dédiée).
                if (str_starts_with($name, 'mount.')
                    || str_starts_with($name, 'system.')
                    || str_starts_with($name, 'db_integrity.')
                    || str_starts_with($name, 'cleanup.')) {
                    continue;
                }
                $containers[] = [
                    'service'    => $service,
                    'cpu'        => $this->getLatestMetric($macDevice, "docker.{$name}.cpu")?->getValue(),
                    'mem'        => $this->getLatestMetric($macDevice, "docker.{$name}.mem")?->getValue(),
                    'mem_mb'     => $this->getLatestMetric($macDevice, "docker.{$name}.mem_mb")?->getValue(),
                    'checked_at' => $service->getCheckedAt(),
                ];
            }
        }

        // Containers NAS depuis la DB
        $nasDevice     = $this->em->getRepository(Device::class)->findOneBy(['hostname' => 'ds-medusa']);
        $nasContainers = [];
        if ($nasDevice) {
            $nasServices = $this->em->createQueryBuilder()
                ->select('s')
                ->from(ServiceStatus::class, 's')
                ->where('s.device = :device')
                ->andWhere('s.name LIKE :pattern')
                ->setParameter('device', $nasDevice)
                ->setParameter('pattern', 'docker.%')
                ->orderBy('s.name', 'ASC')
                ->getQuery()
                ->getResult();

            foreach ($nasServices as $service) {
                $name = substr($service->getName(), 7); // strip 'docker.'
                $nasContainers[] = [
                    'service' => $service,
                    'name'    => $name,
                    'cpu'     => $this->getLatestMetric($nasDevice, "docker.{$name}.cpu")?->getValue(),
                    'mem'     => $this->getLatestMetric($nasDevice, "docker.{$name}.mem")?->getValue(),
                    'mem_mb'  => $this->getLatestMetric($nasDevice, "docker.{$name}.mem_mb")?->getValue(),
                ];
            }
        }

        return $this->render('infrastructure/docker.html.twig', [
            'mac_device'     => $macDevice,
            'containers'     => $containers,
            'nas_containers' => $nasContainers,
        ]);
    }

    /**
     * Redirige vers le premier device surveillé (ordre de découverte).
     */
    #[Route('', name: 'index')]
    public function index(): Response
    {
        $first = $this->em->getRepository(Device::class)
            ->findOneBy(['isMonitored' => true], ['id' => 'ASC']);

        if ($first?->getHostname()) {
            return $this->redirectToRoute('app_infrastructure_device', [
                'hostname' => $first->getHostname(),
            ]);
        }

        return $this->render('infrastructure/empty.html.twig');
    }

    /**
     * Page d'un device — template sélectionné automatiquement selon device.type.
     * Auto-incrémental : tout nouveau device apparu via polling est accessible ici.
     */
    #[Route('/{hostname}', name: 'device')]
    public function device(string $hostname): Response
    {
        $device = $this->em->getRepository(Device::class)
            ->findOneBy(['hostname' => $hostname]);

        if (!$device) {
            throw $this->createNotFoundException("Device « {$hostname} » introuvable.");
        }

        return match ($device->getType()) {
            'server' => $this->renderServer($device),
            'nas'    => $this->renderNas($device),
            'ap'     => $this->renderAp($device),
            default  => $this->render('infrastructure/device/generic.html.twig', [
                'device' => $device,
            ]),
        };
    }

    /**
     * Historique des métriques système Mac — 24h (CPU, RAM, disque, réseau, swap, charge).
     */
    #[Route('/mac/metrics/history', name: 'mac_metrics_history', methods: ['GET'])]
    public function macMetricsHistory(): JsonResponse
    {
        $device = $this->em->getRepository(Device::class)->findOneBy(['hostname' => 'mac-mini']);
        if (!$device) {
            return $this->json(['error' => 'Device mac-mini introuvable'], 404);
        }

        $since  = new \DateTimeImmutable('-24 hours');
        $series = [
            'cpu'        => 'mac.cpu',
            'mem'        => 'mac.mem',
            'disk'       => 'mac.disk',
            'load_1'     => 'mac.load_1',
            'net_in'     => 'mac.net_in_kbs',
            'net_out'    => 'mac.net_out_kbs',
            'swap'       => 'mac.swap',
            'disk_rw'    => 'mac.disk_rw_mbs',
        ];

        $result = ['labels' => []];
        $labelsSet = false;

        foreach ($series as $key => $metricName) {
            $metrics = $this->em->createQueryBuilder()
                ->select('m')
                ->from(Metric::class, 'm')
                ->where('m.device = :device AND m.name = :name AND m.recordedAt >= :since')
                ->setParameter('device', $device)
                ->setParameter('name', $metricName)
                ->setParameter('since', $since)
                ->orderBy('m.recordedAt', 'ASC')
                ->getQuery()
                ->getResult();

            $count  = count($metrics);
            $step   = max(1, (int) ceil($count / 60));
            $values = [];

            foreach ($metrics as $i => $metric) {
                if ($i % $step === 0) {
                    $values[] = round((float) $metric->getValue(), 2);
                    if (!$labelsSet) {
                        $result['labels'][] = $metric->getRecordedAt()->format('H:i');
                    }
                }
            }

            $result[$key] = $values;
            if (!$labelsSet && count($values) > 0) {
                $labelsSet = true;
            }
        }

        return $this->json($result);
    }

    /**
     * Historique CPU+MEM d'un processus Mac (sparkline 24h).
     */
    #[Route('/mac/proc/{name}/history', name: 'mac_proc_history', methods: ['GET'])]
    public function macProcHistory(string $name): JsonResponse
    {
        $device = $this->em->getRepository(Device::class)->findOneBy(['hostname' => 'mac-mini']);
        if (!$device) {
            return $this->json(['error' => 'Device mac-mini introuvable'], 404);
        }

        $safe  = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
        $since = new \DateTimeImmutable('-24 hours');

        $result = ['labels' => [], 'cpu' => [], 'mem' => []];

        foreach (['cpu', 'mem'] as $type) {
            $metrics = $this->em->createQueryBuilder()
                ->select('m')
                ->from(Metric::class, 'm')
                ->where('m.device = :device AND m.name = :name AND m.recordedAt >= :since')
                ->setParameter('device', $device)
                ->setParameter('name', "mac.proc.{$safe}.{$type}")
                ->setParameter('since', $since)
                ->orderBy('m.recordedAt', 'ASC')
                ->getQuery()
                ->getResult();

            $count  = count($metrics);
            $step   = max(1, (int) ceil($count / 60));
            $values = [];

            foreach ($metrics as $i => $metric) {
                if ($i % $step === 0) {
                    $values[] = round((float) $metric->getValue(), 1);
                    if ($type === 'cpu') {
                        $result['labels'][] = $metric->getRecordedAt()->format('H:i');
                    }
                }
            }
            $result[$type] = $values;
        }

        return $this->json(['name' => $name, 'sparkline' => $result]);
    }

    /**
     * Endpoint AJAX : données NAS live (modèle, firmware, disques détaillés, réseau).
     * Appelle directement SynologyClient — pas de cache DB.
     */
    #[Route('/nas/live', name: 'nas_live', methods: ['GET'])]
    public function nasLive(): JsonResponse
    {
        $data = $this->synology->getSystemData();

        if ($data === null) {
            return $this->json(['error' => 'NAS inaccessible'], 503);
        }

        return $this->json([
            'model'        => $data['model'],
            'firmware'     => $data['firmware'],
            'serial'       => $data['serial'],
            'uptime'       => $data['uptime'],
            'ram_size_mb'  => $data['ram_size_mb'],
            'cpu_info'     => $data['cpu_info'],
            'mem_total_mb' => $data['mem_total_mb'],
            'mem_used_mb'  => $data['mem_used_mb'],
            'disks'        => $data['disks'],
            'network'      => $data['network'],
            'volumes'      => $data['volumes'],
            'adapters'     => $data['adapters'],
            'docker'       => $data['docker'],
        ]);
    }

    /**
     * Stats CPU/RAM en temps réel de tous les containers NAS (via docker-socket-proxy).
     */
    #[Route('/nas/docker/stats', name: 'nas_docker_stats', methods: ['GET'])]
    public function nasDockerStats(): JsonResponse
    {
        $stats = $this->synology->getAllContainerStats();
        return $this->json($stats);
    }

    /**
     * Détails d'un container NAS (inspect Docker via docker-socket-proxy).
     */
    #[Route('/nas/docker/{id}/details', name: 'nas_docker_details', methods: ['GET'])]
    public function nasContainerDetails(string $id): JsonResponse
    {
        $inspect = $this->synology->getContainerDetails($id);
        if ($inspect === null) {
            return $this->json(['error' => 'Container introuvable'], 404);
        }

        $ports = [];
        foreach ($inspect['NetworkSettings']['Ports'] ?? [] as $containerPort => $bindings) {
            foreach ((array) $bindings as $b) {
                $ports[] = ['host' => (int) ($b['HostPort'] ?? 0), 'container' => $containerPort];
            }
        }

        $mounts = array_map(fn($m) => [
            'type'        => $m['Type']        ?? 'bind',
            'source'      => $m['Source']      ?? '',
            'destination' => $m['Destination'] ?? '',
            'mode'        => $m['Mode']        ?? 'rw',
        ], $inspect['Mounts'] ?? []);

        $networks = [];
        foreach ($inspect['NetworkSettings']['Networks'] ?? [] as $netName => $net) {
            $rawAliases = $net['Aliases'] ?? [];
            $aliases    = array_values(array_filter($rawAliases, fn($a) => !preg_match('/^[a-f0-9]{12}$/', $a)));
            $networks[] = [
                'name'    => $netName,
                'ip'      => $net['IPAddress'] ?? '',
                'gateway' => $net['Gateway']   ?? '',
                'aliases' => $aliases,
            ];
        }

        $sensitiveKeyPattern = '/password|passwd|secret|token|key|auth|credential|pwd|api_key|passphrase/i';
        $sensitiveValPattern = '/^[a-z][a-z0-9+\-.]*:\/\/.+:.+@/i';
        $env = [];
        foreach ($inspect['Config']['Env'] ?? [] as $raw) {
            $pos    = strpos($raw, '=');
            $k      = $pos !== false ? substr($raw, 0, $pos) : $raw;
            $v      = $pos !== false ? substr($raw, $pos + 1) : '';
            $masked = preg_match($sensitiveKeyPattern, $k) || preg_match($sensitiveValPattern, $v);
            $env[]  = ['key' => $k, 'value' => $masked ? '***' : $v];
        }

        return $this->json([
            'id'             => substr($inspect['Id'] ?? '', 0, 12),
            'name'           => ltrim($inspect['Name'] ?? $id, '/'),
            'image'          => $inspect['Config']['Image'] ?? 'unknown',
            'state'          => $inspect['State']['Status'] ?? 'unknown',
            'started_at'     => $inspect['State']['StartedAt']  ?? null,
            'finished_at'    => $inspect['State']['FinishedAt'] ?? null,
            'exit_code'      => $inspect['State']['ExitCode']   ?? null,
            'error'          => $inspect['State']['Error'] ?? null,
            'hostname'       => $inspect['Config']['Hostname']  ?? '',
            'restart_policy' => $inspect['HostConfig']['RestartPolicy']['Name'] ?? 'no',
            'ports'          => $ports,
            'mounts'         => $mounts,
            'networks'       => $networks,
            'env'            => $env,
            'sparkline'      => $this->buildNasSparkline(ltrim($inspect['Name'] ?? $id, '/')),
        ]);
    }

    /**
     * Logs d'un container NAS (via docker-socket-proxy).
     */
    #[Route('/nas/docker/{id}/logs', name: 'nas_docker_logs', methods: ['GET'])]
    public function nasContainerLogs(string $id): JsonResponse
    {
        return $this->json(['logs' => $this->synology->getContainerLogs($id)]);
    }

    /**
     * Action start / stop / restart sur un container NAS.
     */
    #[Route('/nas/docker/{id}/action', name: 'nas_docker_action', methods: ['POST'])]
    public function nasContainerAction(string $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('nas_container_action', $request->request->get('_token'))) {
            return $this->json(['error' => 'Token CSRF invalide'], 403);
        }

        $action = $request->request->getString('action');
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            return $this->json(['error' => 'Action invalide'], 400);
        }

        return $this->json(['success' => $this->synology->containerAction($id, $action)]);
    }

    /**
     * Endpoint AJAX : données UniFi live (clients + détails AP).
     * Appelle directement UniFiClient — pas de cache DB.
     */
    #[Route('/unifi/live', name: 'unifi_live', methods: ['GET'])]
    public function unifiLive(): JsonResponse
    {
        $data = $this->unifi->getData();

        if ($data === null) {
            return $this->json(['error' => 'UniFi inaccessible'], 503);
        }

        // Clients : champs utiles à l'affichage uniquement
        $clients = array_map(fn($c) => [
            'mac'          => $c['mac'],
            'hostname'     => $c['hostname'],
            'ip'           => $c['ip'],
            'is_wired'     => $c['is_wired'],
            'signal'       => $c['signal'],
            'rssi'         => $c['rssi'],
            'tx_rate'      => $c['tx_rate'],
            'rx_rate'      => $c['rx_rate'],
            'channel'      => $c['channel'],
            'radio_proto'  => $c['radio_proto'],
            'satisfaction' => $c['satisfaction'],
            'network_name' => $c['network_name'],
            'uptime'       => $c['uptime'],
            'oui'          => $c['oui'],
        ], $data['clients']);

        return $this->json([
            'ap'           => $data['ap'],
            'clients'      => $clients,
            'client_count' => $data['client_count'],
            'wifi_count'   => $data['wifi_count'],
            'wired_count'  => $data['wired_count'],
        ]);
    }

    // -----------------------------------------------------------------------

    private function renderServer(Device $device): Response
    {
        $services = $this->em->getRepository(ServiceStatus::class)
            ->findBy(['device' => $device], ['name' => 'ASC']);

        $containers = [];
        foreach ($services as $service) {
            $name = $service->getName();
            // Skip ServiceStatus d'infra (mounts, intégrité SQLite, cleanup, system) —
            // gérés sur la page Intégrité dédiée.
            if (str_starts_with($name, 'mount.')
                || str_starts_with($name, 'system.')
                || str_starts_with($name, 'db_integrity.')
                || str_starts_with($name, 'cleanup.')) {
                continue;
            }
            $containers[] = [
                'service'    => $service,
                'cpu'        => $this->getLatestMetric($device, "docker.{$name}.cpu")?->getValue(),
                'mem'        => $this->getLatestMetric($device, "docker.{$name}.mem")?->getValue(),
                'mem_mb'     => $this->getLatestMetric($device, "docker.{$name}.mem_mb")?->getValue(),
                'checked_at' => $service->getCheckedAt(),
            ];
        }

        return $this->render('infrastructure/device/server.html.twig', [
            'device'     => $device,
            'containers' => $containers,
        ]);
    }

    private function renderNas(Device $device): Response
    {
        $nasMetrics = [
            'cpu'          => $this->getLatestMetric($device, 'synology.cpu')?->getValue(),
            'mem'          => $this->getLatestMetric($device, 'synology.mem')?->getValue(),
            'temp'         => $this->getLatestMetric($device, 'synology.temp')?->getValue(),
            'mem_total_mb' => $this->getLatestMetric($device, 'synology.mem_total_mb')?->getValue(),
            'mem_used_mb'  => $this->getLatestMetric($device, 'synology.mem_used_mb')?->getValue(),
        ];

        // Volumes
        $allMetricNames = $this->em->createQueryBuilder()
            ->select('DISTINCT m.name')
            ->from(Metric::class, 'm')
            ->where('m.device = :device')
            ->andWhere('m.name LIKE :pattern')
            ->setParameter('device', $device)
            ->setParameter('pattern', 'synology.%.used_percent')
            ->getQuery()
            ->getArrayResult();

        $nasVolumes = [];
        foreach (array_column($allMetricNames, 'name') as $metricName) {
            preg_match('/^synology\.(.+)\.used_percent$/', $metricName, $m);
            $volId = $m[1] ?? null;
            if (!$volId) {
                continue;
            }
            $nasVolumes[] = [
                'id'       => $volId,
                'percent'  => $this->getLatestMetric($device, "synology.{$volId}.used_percent")?->getValue(),
                'used_gb'  => $this->getLatestMetric($device, "synology.{$volId}.used_gb")?->getValue(),
                'total_gb' => $this->getLatestMetric($device, "synology.{$volId}.total_gb")?->getValue(),
            ];
        }

        // Disques physiques (températures depuis DB)
        $diskMetricNames = $this->em->createQueryBuilder()
            ->select('DISTINCT m.name')
            ->from(Metric::class, 'm')
            ->where('m.device = :device')
            ->andWhere('m.name LIKE :pattern')
            ->setParameter('device', $device)
            ->setParameter('pattern', 'synology.disk.%.temp')
            ->getQuery()
            ->getArrayResult();

        $nasDisksDb = [];
        foreach (array_column($diskMetricNames, 'name') as $metricName) {
            preg_match('/^synology\.disk\.(.+)\.temp$/', $metricName, $m);
            $diskId = $m[1] ?? null;
            if (!$diskId) {
                continue;
            }
            $nasDisksDb[$diskId] = $this->getLatestMetric($device, "synology.disk.{$diskId}.temp")?->getValue();
        }

        $nasServices = $this->em->getRepository(ServiceStatus::class)
            ->findBy(['device' => $device], ['name' => 'ASC']);

        return $this->render('infrastructure/device/nas.html.twig', [
            'device'       => $device,
            'nas_metrics'  => $nasMetrics,
            'nas_volumes'  => $nasVolumes,
            'nas_disks_db' => $nasDisksDb,
            'nas_services' => $nasServices,
        ]);
    }

    private function renderAp(Device $device): Response
    {
        $healthServices = $this->em->getRepository(ServiceStatus::class)
            ->findBy(['device' => $device]);

        $unifiHealth = [];
        foreach ($healthServices as $svc) {
            $key = str_replace('unifi.health.', '', $svc->getName());
            $unifiHealth[$key] = $svc->getStatus();
        }

        $unifiMetrics = [
            'clients_total' => $this->getLatestMetric($device, 'unifi.clients.total')?->getValue(),
            'clients_wifi'  => $this->getLatestMetric($device, 'unifi.clients.wifi')?->getValue(),
            'clients_wired' => $this->getLatestMetric($device, 'unifi.clients.wired')?->getValue(),
            'ap_uptime'     => $this->getLatestMetric($device, 'unifi.ap.uptime')?->getValue(),
            'ap_tx_bytes'   => $this->getLatestMetric($device, 'unifi.ap.tx_bytes')?->getValue(),
            'ap_rx_bytes'   => $this->getLatestMetric($device, 'unifi.ap.rx_bytes')?->getValue(),
            'ap_satisfaction' => $this->getLatestMetric($device, 'unifi.ap.satisfaction')?->getValue(),
            'ap_num_sta'    => $this->getLatestMetric($device, 'unifi.ap.num_sta')?->getValue(),
        ];

        return $this->render('infrastructure/device/ap.html.twig', [
            'device'         => $device,
            'unifi_health'   => $unifiHealth,
            'unifi_metrics'  => $unifiMetrics,
        ]);
    }

    // -----------------------------------------------------------------------

    private function buildNasSparkline(string $containerName): array
    {
        $device = $this->em->getRepository(Device::class)->findOneBy(['hostname' => 'ds-medusa']);
        if (!$device) {
            return ['labels' => [], 'cpu' => [], 'mem' => []];
        }

        $since  = new \DateTimeImmutable('-24 hours');
        $result = ['labels' => [], 'cpu' => [], 'mem' => []];

        foreach (['cpu', 'mem'] as $type) {
            $metrics = $this->em->createQueryBuilder()
                ->select('m')
                ->from(Metric::class, 'm')
                ->where('m.device = :device AND m.name = :name AND m.recordedAt >= :since')
                ->setParameter('device', $device)
                ->setParameter('name', "docker.{$containerName}.{$type}")
                ->setParameter('since', $since)
                ->orderBy('m.recordedAt', 'ASC')
                ->getQuery()
                ->getResult();

            $count  = count($metrics);
            $step   = max(1, (int) ceil($count / 48));
            $values = [];
            $labels = [];

            foreach ($metrics as $i => $metric) {
                if ($i % $step === 0) {
                    $values[] = round((float) $metric->getValue(), 1);
                    if ($type === 'cpu') {
                        $labels[] = $metric->getRecordedAt()->format('H:i');
                    }
                }
            }

            $result[$type] = $values;
            if ($type === 'cpu') {
                $result['labels'] = $labels;
            }
        }

        return $result;
    }

    private function getLatestMetric(Device $device, string $name): ?Metric
    {
        return $this->em->createQueryBuilder()
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
    }
}
