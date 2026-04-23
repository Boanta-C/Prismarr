<?php

namespace App\Controller;

use App\Service\HealthService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    private const MONITORED_SERVICES = ['radarr', 'sonarr', 'prowlarr', 'jellyseerr', 'qbittorrent', 'tmdb'];

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function health(Connection $db): JsonResponse
    {
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        try {
            $db->executeQuery('SELECT 1');
        } catch (\Throwable) {
            return new JsonResponse(
                ['status' => 'error', 'db' => 'unreachable', 'timestamp' => $timestamp],
                503
            );
        }

        return new JsonResponse(
            ['status' => 'ok', 'db' => 'ok', 'timestamp' => $timestamp],
            200
        );
    }

    /**
     * Per-service health snapshot for the topbar indicator. Returns a map
     * { radarr: bool|null, ... } where `true` = up, `false` = unreachable,
     * `null` = not configured. Results come from the shared HealthService
     * 10s cache so polling this every few seconds is cheap.
     */
    #[Route('/api/health/services', name: 'api_health_services', methods: ['GET'])]
    public function servicesHealth(HealthService $health): JsonResponse
    {
        $out = [];
        $ok  = 0;
        $total = 0;

        foreach (self::MONITORED_SERVICES as $service) {
            try {
                $state = $health->isHealthy($service);
            } catch (\Throwable) {
                $state = null;
            }

            $out[$service] = $state;
            if ($state !== null) {
                $total++;
                if ($state) {
                    $ok++;
                }
            }
        }

        return new JsonResponse([
            'services'  => $out,
            'ok'        => $ok,
            'total'     => $total,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
