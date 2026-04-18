<?php

namespace App\Controller;

use App\Entity\Infrastructure\Device;
use App\Entity\Infrastructure\Metric;
use App\Service\Docker\DockerClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/container', name: 'app_container_')]
class ContainerController extends AbstractController
{
    public function __construct(
        private readonly DockerClient          $docker,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Données complètes pour le modal d'un container :
     * - inspect Docker (image, ports, mounts, état)
     * - sparklines CPU + RAM sur les dernières 24h (BDD)
     */
    #[Route('/{name}/details', name: 'details', methods: ['GET'])]
    public function details(string $name): JsonResponse
    {
        $inspect = $this->docker->inspect($name);
        if ($inspect === null) {
            return $this->json(['error' => 'Container introuvable'], 404);
        }

        // Ports mappés
        $ports = [];
        foreach ($inspect['NetworkSettings']['Ports'] ?? [] as $containerPort => $bindings) {
            foreach ((array) $bindings as $b) {
                $ports[] = ['host' => (int) ($b['HostPort'] ?? 0), 'container' => $containerPort];
            }
        }

        // Volumes / bind mounts
        $mounts = array_map(fn($m) => [
            'type'        => $m['Type']        ?? 'bind',
            'source'      => $m['Source']      ?? '',
            'destination' => $m['Destination'] ?? '',
            'mode'        => $m['Mode']        ?? 'rw',
        ], $inspect['Mounts'] ?? []);

        // Réseaux + IPs
        $networks = [];
        foreach ($inspect['NetworkSettings']['Networks'] ?? [] as $netName => $net) {
            $rawAliases = $net['Aliases'] ?? [];
            $aliases = array_values(array_filter($rawAliases, fn($a) => !preg_match('/^[a-f0-9]{12}$/', $a)));
            $networks[] = [
                'name'    => $netName,
                'ip'      => $net['IPAddress'] ?? '',
                'gateway' => $net['Gateway'] ?? '',
                'aliases' => $aliases,
            ];
        }

        // Variables d'environnement (valeurs sensibles masquées)
        $sensitiveKeyPattern = '/password|passwd|secret|token|key|auth|credential|pwd|api_key|passphrase/i';
        $sensitiveValPattern = '/^[a-z][a-z0-9+\-.]*:\/\/.+:.+@/i'; // URLs avec user:pass@host
        $env = [];
        foreach ($inspect['Config']['Env'] ?? [] as $raw) {
            $pos   = strpos($raw, '=');
            $k     = $pos !== false ? substr($raw, 0, $pos) : $raw;
            $v     = $pos !== false ? substr($raw, $pos + 1) : '';
            $masked = preg_match($sensitiveKeyPattern, $k) || preg_match($sensitiveValPattern, $v);
            $env[] = ['key' => $k, 'value' => $masked ? '***' : $v];
        }

        // Restart policy
        $restartPolicy = $inspect['HostConfig']['RestartPolicy']['Name'] ?? 'no';

        return $this->json([
            'id'             => substr($inspect['Id'] ?? '', 0, 12),
            'name'           => ltrim($inspect['Name'] ?? $name, '/'),
            'image'          => $inspect['Config']['Image'] ?? 'unknown',
            'state'          => $inspect['State']['Status'] ?? 'unknown',
            'started_at'     => $inspect['State']['StartedAt'] ?? null,
            'finished_at'    => $inspect['State']['FinishedAt'] ?? null,
            'exit_code'      => $inspect['State']['ExitCode'] ?? null,
            'error'          => $inspect['State']['Error'] ?? null,
            'hostname'       => $inspect['Config']['Hostname'] ?? '',
            'restart_policy' => $restartPolicy,
            'ports'          => $ports,
            'mounts'         => $mounts,
            'networks'       => $networks,
            'env'            => $env,
            'sparkline'      => $this->buildSparkline($name),
        ]);
    }

    /**
     * Dernières lignes de logs (50 par défaut).
     */
    #[Route('/{name}/logs', name: 'logs', methods: ['GET'])]
    public function logs(string $name): JsonResponse
    {
        return $this->json(['logs' => $this->docker->getLogs($name, 100)]);
    }

    /**
     * Exécute start / stop / restart sur un container.
     * Les containers argos_* (système) sont protégés.
     */
    #[Route('/{name}/action', name: 'action', methods: ['POST'])]
    public function action(string $name, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('container_action_' . $name, $request->request->get('_token'))) {
            return $this->json(['error' => 'Token CSRF invalide'], 403);
        }

        if (str_starts_with($name, 'argos_')) {
            return $this->json(['error' => 'Actions désactivées sur les containers système argos_*'], 403);
        }

        $action = $request->request->getString('action');
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            return $this->json(['error' => 'Action invalide'], 400);
        }

        return $this->json(['success' => $this->docker->performAction($name, $action)]);
    }

    // -----------------------------------------------------------------------

    /**
     * Construit les données sparklines CPU + RAM pour les 24 dernières heures.
     * Downsample à ~48 points (une mesure toutes les 30 min environ).
     */
    private function buildSparkline(string $containerName): array
    {
        $device = $this->em->getRepository(Device::class)->findOneBy(['hostname' => 'mac-mini']);
        if (!$device) {
            return ['labels' => [], 'cpu' => [], 'mem' => []];
        }

        $since  = new \DateTimeImmutable('-24 hours');
        $result = ['labels' => [], 'cpu' => [], 'mem' => []];

        foreach (['cpu', 'mem'] as $type) {
            /** @var Metric[] $metrics */
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
}
