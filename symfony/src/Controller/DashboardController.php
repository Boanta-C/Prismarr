<?php

namespace App\Controller;

use App\Entity\Infrastructure\Alert;
use App\Entity\Infrastructure\Device;
use App\Entity\Infrastructure\Metric;
use App\Entity\Infrastructure\ServiceStatus;
use App\Repository\Infrastructure\DeviceRepository;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\WeatherClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeatherClient $weatherClient,
        private readonly RadarrClient $radarr,
        private readonly SonarrClient $sonarr,
        private readonly CacheInterface $cache,
    ) {}

    #[Route('/', name: 'app_dashboard')]
    public function index(DeviceRepository $deviceRepo): Response
    {
        $macMini = $deviceRepo->findOneBy(['hostname' => 'mac-mini']);
        $nas     = $deviceRepo->findOneBy(['hostname' => 'ds-medusa']);
        $ap      = $deviceRepo->findOneBy(['hostname' => 'unifi-ap']);

        // Containers Docker Mac Mini
        $services        = $macMini
            ? $this->em->getRepository(ServiceStatus::class)->findBy(['device' => $macMini])
            : [];
        $containersUp    = count(array_filter($services, fn($s) => $s->getStatus() === 'up'));
        $containersTotal = count($services);

        // Mac Mini métriques
        $macCpu  = $this->latestMetric($macMini, 'mac.cpu');
        $macRam  = $this->latestMetric($macMini, 'mac.mem');
        $macDisk = $this->latestMetric($macMini, 'mac.disk');

        // NAS métriques
        $nasCpu  = $this->latestMetric($nas, 'synology.cpu');
        $nasRam  = $this->latestMetric($nas, 'synology.mem');
        $nasTemp = $this->latestMetric($nas, 'synology.temp');

        // NAS volumes
        $nasVolumes = $this->nasVolumes($nas);

        // UniFi clients
        $clientsTotal = $this->latestMetric($ap, 'unifi.clients.total');
        $clientsWifi  = $this->latestMetric($ap, 'unifi.clients.wifi');
        $clientsWired = $this->latestMetric($ap, 'unifi.clients.wired');

        // Météo courante (depuis le cache Redis — jamais bloquant)
        $weatherCurrent = null;
        try {
            $forecast = $this->weatherClient->getForecast();
            if ($forecast && !empty($forecast['hourly']['time'])) {
                $weatherCurrent = $this->extractDashboardWeather($forecast);
            }
        } catch (\Throwable) {}

        // Phase lunaire
        $moonCurrent = $this->getCurrentMoon();

        // Médias — chargés en AJAX pour ne pas bloquer le rendu du dashboard

        // Alertes actives
        $activeAlerts = $this->em->getRepository(Alert::class)
            ->createQueryBuilder('a')
            ->where('a.resolvedAt IS NULL')
            ->orderBy('a.severity', 'DESC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('dashboard/index.html.twig', [
            'macMini'          => $macMini,
            'nas'              => $nas,
            'ap'               => $ap,
            'services'         => $services,
            'containers_up'    => $containersUp,
            'containers_total' => $containersTotal,
            'mac_cpu'          => $macCpu,
            'mac_ram'          => $macRam,
            'mac_disk'         => $macDisk,
            'nas_cpu'          => $nasCpu,
            'nas_ram'          => $nasRam,
            'nas_temp'         => $nasTemp,
            'nas_volumes'      => $nasVolumes,
            'clients_total'    => $clientsTotal,
            'clients_wifi'     => $clientsWifi,
            'clients_wired'    => $clientsWired,
            'active_alerts'    => $activeAlerts,
            'weather_current'  => $weatherCurrent,
            'moon_current'     => $moonCurrent,
        ]);
    }

    #[Route('/dashboard/media-widgets', name: 'app_dashboard_media_widgets', methods: ['GET'])]
    public function mediaWidgets(): JsonResponse
    {
        // Tout est caché 60s pour ne pas bloquer les workers php-fpm
        $result = $this->cache->get('argos_dashboard_widgets', function (ItemInterface $item) {
            $item->expiresAfter(60);

            $recent   = [];
            $calendar = [];
            $queue    = [];

            try {
                $rawMovies = $this->radarr->getRawMovies();
                usort($rawMovies, fn($a, $b) => ($b['added'] ?? '') <=> ($a['added'] ?? ''));
                foreach (array_slice($rawMovies, 0, 5) as $m) {
                    $recent[] = ['type' => 'film', 'title' => $m['title'] ?? '—', 'poster' => $this->extractPoster($m), 'year' => $m['year'] ?? null, 'hasFile' => (bool) ($m['hasFile'] ?? false)];
                }
            } catch (\Throwable) {}
            try {
                $rawSeries = $this->sonarr->getRawAllSeries();
                usort($rawSeries, fn($a, $b) => ($b['added'] ?? '') <=> ($a['added'] ?? ''));
                foreach (array_slice($rawSeries, 0, 5) as $s) {
                    $recent[] = ['type' => 'serie', 'title' => $s['title'] ?? '—', 'poster' => $this->extractPoster($s), 'year' => $s['year'] ?? null, 'hasFile' => true];
                }
            } catch (\Throwable) {}

            try {
                $calEpisodes = $this->sonarr->getCalendar(14);
                foreach (array_slice($calEpisodes, 0, 6) as $e) {
                    $d = $e['airDate'] ?? null;
                    $calendar[] = ['type' => 'episode', 'title' => ($e['seriesTitle'] ?? '') . ' S' . str_pad($e['season'] ?? 0, 2, '0', STR_PAD_LEFT) . 'E' . str_pad($e['episode'] ?? 0, 2, '0', STR_PAD_LEFT), 'poster' => $e['poster'] ?? null, 'date' => $d instanceof \DateTimeInterface ? $d->format('d/m') : $d];
                }
            } catch (\Throwable) {}

            try {
                $radarrQueue = $this->radarr->getQueue();
                $sonarrQueue = $this->sonarr->getQueue();
                foreach (array_slice($radarrQueue, 0, 3) as $q) {
                    $size = (float) ($q['size'] ?? 0);
                    $left = (float) ($q['sizeleft'] ?? 0);
                    $queue[] = ['type' => 'film', 'title' => $q['title'] ?? '—', 'progress' => $size > 0 ? round((($size - $left) / $size) * 100, 1) : 0];
                }
                foreach (array_slice($sonarrQueue, 0, 3) as $q) {
                    $size = (float) ($q['size'] ?? 0);
                    $left = (float) ($q['sizeleft'] ?? 0);
                    $queue[] = ['type' => 'episode', 'title' => $q['title'] ?? '—', 'progress' => $size > 0 ? round((($size - $left) / $size) * 100, 1) : 0];
                }
            } catch (\Throwable) {}

            return ['recent' => array_slice($recent, 0, 6), 'calendar' => array_slice($calendar, 0, 6), 'queue' => array_slice($queue, 0, 5)];
        });

        return $this->json($result);
    }

    private function extractDashboardWeather(array $forecast): array
    {
        $tz     = new \DateTimeZone('Europe/Paris');
        $hourly = $forecast['hourly'] ?? [];
        $times  = $hourly['time'] ?? [];
        $now    = new \DateTimeImmutable('now', $tz);
        $idx    = 0;

        foreach ($times as $i => $timeStr) {
            if ((new \DateTimeImmutable($timeStr, $tz)) <= $now) {
                $idx = $i;
            } else {
                break;
            }
        }

        $isDay = (bool) ($hourly['is_day'][$idx] ?? 1);
        $code  = (int) ($hourly['weather_code'][$idx] ?? 0);

        $emojis = [
            0 => '☀️', 1 => '🌤️', 2 => '⛅', 3 => '☁️',
            45 => '🌫️', 48 => '🌫️',
            51 => '🌦️', 53 => '🌦️', 55 => '🌧️',
            61 => '🌧️', 63 => '🌧️', 65 => '🌧️',
            71 => '❄️', 73 => '❄️', 75 => '❄️', 77 => '❄️',
            80 => '🌦️', 81 => '🌧️', 82 => '⛈️',
            85 => '🌨️', 86 => '🌨️',
            95 => '⛈️', 96 => '⛈️', 99 => '⛈️',
        ];
        $emoji = $isDay ? ($emojis[$code] ?? '🌡️') : ($code <= 1 ? '🌙' : ($emojis[$code] ?? '🌡️'));

        $labels = [
            0 => 'Dégagé', 1 => 'Principalement dégagé', 2 => 'Partiellement nuageux', 3 => 'Couvert',
            45 => 'Brouillard', 48 => 'Brouillard givrant',
            51 => 'Bruine légère', 53 => 'Bruine', 55 => 'Bruine dense',
            61 => 'Pluie légère', 63 => 'Pluie', 65 => 'Pluie forte',
            71 => 'Neige légère', 73 => 'Neige', 75 => 'Neige forte',
            80 => 'Averses légères', 81 => 'Averses', 82 => 'Averses fortes',
            85 => 'Averses de neige', 86 => 'Averses de neige fortes',
            95 => 'Orage', 96 => 'Orage avec grêle', 99 => 'Orage violent',
        ];

        $daily   = $forecast['daily'] ?? [];
        $tempMax = isset($daily['temperature_2m_max'][0]) ? (int) round((float) $daily['temperature_2m_max'][0]) : null;
        $tempMin = isset($daily['temperature_2m_min'][0]) ? (int) round((float) $daily['temperature_2m_min'][0]) : null;

        $type = match(true) {
            in_array($code, [95, 96, 99])                          => 'storm',
            in_array($code, [71, 73, 75, 77, 85, 86])             => 'snow',
            in_array($code, [65, 67, 82])                          => 'heavy_rain',
            in_array($code, [51, 53, 55, 56, 57, 61, 63, 80, 81]) => 'rain',
            in_array($code, [66])                                  => 'sleet',
            in_array($code, [45, 48])                              => 'fog',
            in_array($code, [3])                                   => 'cloudy',
            in_array($code, [1, 2])                                => 'partly_cloudy',
            default                                                => 'clear',
        };

        return [
            'temp'        => round((float) ($hourly['temperature_2m'][$idx] ?? 0), 1),
            'feels_like'  => round((float) ($hourly['apparent_temperature'][$idx] ?? 0), 1),
            'precip_prob' => (int) ($hourly['precipitation_probability'][$idx] ?? 0),
            'wind'        => (int) round((float) ($hourly['wind_speed_10m'][$idx] ?? 0)),
            'humidity'    => (int) ($hourly['relative_humidity_2m'][$idx] ?? 0),
            'uv'          => round((float) ($hourly['uv_index'][$idx] ?? 0), 1),
            'emoji'       => $emoji,
            'label'       => $labels[$code] ?? 'Conditions météo',
            'temp_max'    => $tempMax,
            'temp_min'    => $tempMin,
            'location'    => $this->weatherClient->getLocationName(),
            'type'        => $type,
            'is_day'      => $isDay,
        ];
    }

    private function nasVolumes(?Device $device): array
    {
        if (!$device) {
            return [];
        }

        $rows = $this->em->createQuery(
            "SELECT DISTINCT m.name FROM App\\Entity\\Infrastructure\\Metric m
             WHERE m.device = :device AND m.name LIKE 'synology.%.used_percent'
             AND m.recordedAt > :cutoff"
        )
            ->setParameter('device', $device)
            ->setParameter('cutoff', new \DateTimeImmutable('-2 hours'))
            ->getArrayResult();

        $volumes = [];
        foreach (array_column($rows, 'name') as $metricName) {
            $parts = explode('.', $metricName);
            $volId = $parts[1] ?? null;
            if (!$volId) {
                continue;
            }

            $usedPct = $this->latestMetric($device, "synology.{$volId}.used_percent");
            $usedGb  = $this->latestMetric($device, "synology.{$volId}.used_gb");
            $totalGb = $this->latestMetric($device, "synology.{$volId}.total_gb");

            if ($usedPct === null) {
                continue;
            }

            $volumes[] = [
                'id'       => $volId,
                'used_pct' => $usedPct,
                'used_gb'  => $usedGb,
                'total_gb' => $totalGb,
            ];
        }

        usort($volumes, fn($a, $b) => strcmp($a['id'], $b['id']));

        return $volumes;
    }

    private function latestMetric(?Device $device, string $name): ?float
    {
        if (!$device) {
            return null;
        }

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

    private function getCurrentMoon(): array
    {
        $tz    = new \DateTimeZone('Europe/Paris');
        $now   = new \DateTimeImmutable('now', $tz);
        $jd    = $now->getTimestamp() / 86400.0 + 2440587.5;
        $phase = fmod(($jd - 2451549.5754) / 29.53058770576, 1.0);
        if ($phase < 0.0) $phase += 1.0;

        $label = match(true) {
            $phase < 0.03 || $phase > 0.97 => 'Nouvelle Lune',
            $phase < 0.22                  => 'Croissant croissant',
            $phase < 0.28                  => 'Premier Quartier',
            $phase < 0.47                  => 'Gibbeuse croissante',
            $phase < 0.53                  => 'Pleine Lune',
            $phase < 0.72                  => 'Gibbeuse décroissante',
            $phase < 0.78                  => 'Dernier Quartier',
            default                        => 'Croissant décroissant',
        };

        $emoji = match(true) {
            $phase < 0.0625 => '🌑', $phase < 0.1875 => '🌒', $phase < 0.3125 => '🌓',
            $phase < 0.4375 => '🌔', $phase < 0.5625 => '🌕', $phase < 0.6875 => '🌖',
            $phase < 0.8125 => '🌗', $phase < 0.9375 => '🌘', default         => '🌑',
        };

        return [
            'emoji'        => $emoji,
            'label'        => $label,
            'illumination' => (int) round(abs(sin($phase * M_PI)) * 100),
        ];
    }

    private function extractPoster(array $item): ?string
    {
        foreach ($item['images'] ?? [] as $img) {
            if (($img['coverType'] ?? '') === 'poster') {
                return $img['remoteUrl'] ?? ($img['url'] ?? null) ?: null;
            }
        }
        return null;
    }
}
