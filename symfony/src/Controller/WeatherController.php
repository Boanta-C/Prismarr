<?php

namespace App\Controller;

use App\Service\WeatherClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/meteo', name: 'app_weather')]
class WeatherController extends AbstractController
{
    // WMO weather code → libellé français
    private const WMO_LABELS = [
        0  => 'Ciel dégagé',       1  => 'Principalement dégagé',
        2  => 'Partiellement nuageux', 3  => 'Couvert',
        45 => 'Brouillard',        48 => 'Brouillard givrant',
        51 => 'Bruine légère',     53 => 'Bruine modérée',     55 => 'Bruine dense',
        56 => 'Bruine verglaçante légère', 57 => 'Bruine verglaçante dense',
        61 => 'Pluie légère',      63 => 'Pluie modérée',      65 => 'Pluie forte',
        66 => 'Pluie verglaçante légère', 67 => 'Pluie verglaçante forte',
        71 => 'Neige légère',      73 => 'Neige modérée',      75 => 'Neige forte',
        77 => 'Grains de neige',
        80 => 'Averses légères',   81 => 'Averses modérées',   82 => 'Averses violentes',
        85 => 'Averses de neige légères', 86 => 'Averses de neige fortes',
        95 => 'Orage',             96 => 'Orage avec grêle légère', 99 => 'Orage avec grêle forte',
    ];

    private const WMO_EMOJI = [
        0  => '☀️', 1  => '🌤️', 2  => '⛅', 3  => '☁️',
        45 => '🌫️', 48 => '🌫️',
        51 => '🌦️', 53 => '🌦️', 55 => '🌧️',
        56 => '🌧️', 57 => '🌧️',
        61 => '🌧️', 63 => '🌧️', 65 => '🌧️',
        66 => '🌨️', 67 => '🌨️',
        71 => '❄️', 73 => '❄️', 75 => '❄️', 77 => '❄️',
        80 => '🌦️', 81 => '🌧️', 82 => '⛈️',
        85 => '🌨️', 86 => '🌨️',
        95 => '⛈️', 96 => '⛈️', 99 => '⛈️',
    ];

    public function __construct(private readonly WeatherClient $weatherClient) {}

    #[Route('', name: '')]
    public function index(): Response
    {
        $forecast = $this->weatherClient->getForecast();
        $tz       = new \DateTimeZone('Europe/Paris');

        $apiError = $forecast['_error'] ?? null;
        if (empty($forecast) || $apiError) {
            return $this->render('weather/index.html.twig', [
                'error'        => true,
                'error_reason' => $apiError ?: 'Aucune donnée reçue de l\'API météo.',
                'location'     => $this->weatherClient->getLocationName(),
                'days'         => [], 'storm_hours' => [],
                'moon'         => null, 'today'      => null,
                'current'      => null, 'hourly_chart' => '{}',
                'days_chart_json' => '[]',
                'week_stats'   => null,
                'storm_next_24h' => false, 'storm_next_48h' => false,
                'fetched_ago'  => null,
            ]);
        }

        $days         = $this->processDays($forecast, $tz);
        $stormHours   = $this->processStormHours($forecast, $tz);
        $moon         = $this->computeMoonPhases($tz);
        $today        = $days[0] ?? null;
        $current      = $this->processCurrentHour($forecast, $tz);
        $weekStats    = $this->buildWeekStats($days);
        $daysChartJson = json_encode($this->buildDaysChartData($forecast, $tz));

        $now = new \DateTimeImmutable('now', $tz);
        $stormNext24h = !empty(array_filter($stormHours, fn (array $h) => $h['datetime'] <= $now->modify('+24 hours')));
        $stormNext48h = !empty(array_filter($stormHours, fn (array $h) => $h['datetime'] <= $now->modify('+48 hours')));

        $fetchedAt  = $forecast['_fetched_at'] ?? null;
        $fetchedAgo = $fetchedAt ? max(0, (int) round((time() - $fetchedAt) / 60)) : null;

        return $this->render('weather/index.html.twig', [
            'error'           => false,
            'location'        => $this->weatherClient->getLocationName(),
            'days'            => $days,
            'storm_hours'     => $stormHours,
            'moon'            => $moon,
            'today'           => $today,
            'current'         => $current,
            'days_chart_json' => $daysChartJson,
            'week_stats'      => $weekStats,
            'storm_next_24h'  => $stormNext24h,
            'storm_next_48h'  => $stormNext48h,
            'fetched_ago'     => $fetchedAgo,
        ]);
    }

    // =========================================================================
    // Données journalières
    // =========================================================================

    private function processDays(array $forecast, \DateTimeZone $tz): array
    {
        $d    = $forecast['daily'] ?? [];
        $days = [];

        foreach (($d['time'] ?? []) as $i => $dateStr) {
            $date    = new \DateTimeImmutable($dateStr, $tz);
            $code    = (int) ($d['weather_code'][$i] ?? 0);
            $sunrise = isset($d['sunrise'][$i]) ? new \DateTimeImmutable($d['sunrise'][$i]) : null;
            $sunset  = isset($d['sunset'][$i])  ? new \DateTimeImmutable($d['sunset'][$i])  : null;

            $sunshineSecs  = (float) ($d['sunshine_duration'][$i] ?? 0);
            $daylightSecs  = (float) ($d['daylight_duration'][$i] ?? 0);

            $days[] = [
                'date'            => $date,
                'date_label'      => $this->formatDateLabel($date, $i),
                'date_short'      => $date->format('d/m'),
                'code'            => $code,
                'emoji'           => self::WMO_EMOJI[$code] ?? '❓',
                'label'           => self::WMO_LABELS[$code] ?? "Code $code",
                'type'            => $this->getWeatherType($code),
                'temp_max'        => $d['temperature_2m_max'][$i] ?? null,
                'temp_min'        => $d['temperature_2m_min'][$i] ?? null,
                'feels_max'       => $d['apparent_temperature_max'][$i] ?? null,
                'feels_min'       => $d['apparent_temperature_min'][$i] ?? null,
                'precip_sum'      => round((float) ($d['precipitation_sum'][$i] ?? 0), 1),
                'precip_hours'    => (int) ($d['precipitation_hours'][$i] ?? 0),
                'rain_sum'        => round((float) ($d['rain_sum'][$i] ?? 0), 1),
                'showers_sum'     => round((float) ($d['showers_sum'][$i] ?? 0), 1),
                'snowfall'        => round((float) ($d['snowfall_sum'][$i] ?? 0), 1),
                'precip_prob'     => (int) ($d['precipitation_probability_max'][$i] ?? 0),
                'wind_max'        => round((float) ($d['wind_speed_10m_max'][$i] ?? 0)),
                'wind_gusts'      => round((float) ($d['wind_gusts_10m_max'][$i] ?? 0)),
                'wind_dir'        => (int) ($d['wind_direction_10m_dominant'][$i] ?? 0),
                'wind_dir_label'  => $this->degreesToCompass((int) ($d['wind_direction_10m_dominant'][$i] ?? 0)),
                'uv_max'          => round((float) ($d['uv_index_max'][$i] ?? 0), 1),
                'sunshine_hours'  => round($sunshineSecs / 3600, 1),
                'daylight_hours'  => round($daylightSecs / 3600, 1),
                'sunrise'         => $sunrise,
                'sunset'          => $sunset,
            ];
        }

        return $days;
    }

    private function formatDateLabel(\DateTimeImmutable $date, int $index): string
    {
        if ($index === 0) return 'Aujourd\'hui';
        if ($index === 1) return 'Demain';

        $days   = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
        $months = ['jan', 'fév', 'mar', 'avr', 'mai', 'juin', 'juil', 'aoû', 'sep', 'oct', 'nov', 'déc'];

        return $days[(int) $date->format('w')] . ' ' . (int) $date->format('j') . ' ' . $months[(int) $date->format('n') - 1];
    }

    private function getWeatherType(int $code): string
    {
        return match (true) {
            in_array($code, [95, 96, 99])             => 'storm',
            in_array($code, [71, 73, 75, 77, 85, 86]) => 'snow',
            in_array($code, [65, 67, 82])              => 'heavy_rain',
            in_array($code, [51, 53, 55, 56, 57, 61, 63, 80, 81]) => 'rain',
            in_array($code, [66])                      => 'sleet',
            in_array($code, [45, 48])                  => 'fog',
            in_array($code, [3])                       => 'cloudy',
            in_array($code, [1, 2])                    => 'partly_cloudy',
            default                                    => 'clear',
        };
    }

    // =========================================================================
    // Conditions actuelles (heure courante)
    // =========================================================================

    private function processCurrentHour(array $forecast, \DateTimeZone $tz): array
    {
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

        $windDir = (int) ($hourly['wind_direction_10m'][$idx] ?? 0);
        $vis     = (int) ($hourly['visibility'][$idx] ?? 0);
        $code    = (int) ($hourly['weather_code'][$idx] ?? 0);
        $isDay   = (bool) ($hourly['is_day'][$idx] ?? 1);

        return [
            'temp'           => round((float) ($hourly['temperature_2m'][$idx] ?? 0), 1),
            'feels_like'     => round((float) ($hourly['apparent_temperature'][$idx] ?? 0), 1),
            'humidity'       => (int) ($hourly['relative_humidity_2m'][$idx] ?? 0),
            'dew_point'      => round((float) ($hourly['dew_point_2m'][$idx] ?? 0), 1),
            'pressure'       => round((float) ($hourly['surface_pressure'][$idx] ?? 0)),
            'cloud'          => (int) ($hourly['cloud_cover'][$idx] ?? 0),
            'visibility'     => $vis,
            'visibility_km'  => round($vis / 1000, 1),
            'uv'             => round((float) ($hourly['uv_index'][$idx] ?? 0), 1),
            'uv_label'       => $this->uvLabel((float) ($hourly['uv_index'][$idx] ?? 0)),
            'uv_color'       => $this->uvColor((float) ($hourly['uv_index'][$idx] ?? 0)),
            'wind'           => round((float) ($hourly['wind_speed_10m'][$idx] ?? 0)),
            'gusts'          => round((float) ($hourly['wind_gusts_10m'][$idx] ?? 0)),
            'wind_dir'       => $windDir,
            'wind_dir_label' => $this->degreesToCompass($windDir),
            'precip'         => round((float) ($hourly['precipitation'][$idx] ?? 0), 1),
            'precip_prob'    => (int) ($hourly['precipitation_probability'][$idx] ?? 0),
            'code'           => $code,
            'is_day'         => $isDay,
            'label'          => self::WMO_LABELS[$code] ?? "Code $code",
            'emoji'          => $isDay ? (self::WMO_EMOJI[$code] ?? '🌡️') : ($code <= 1 ? '🌙' : (self::WMO_EMOJI[$code] ?? '🌡️')),
            'type'           => $this->getWeatherType($code),
        ];
    }

    // =========================================================================
    // Orages
    // =========================================================================

    private function processStormHours(array $forecast, \DateTimeZone $tz): array
    {
        $hourly = $forecast['hourly'] ?? [];
        $times  = $hourly['time'] ?? [];
        $now    = new \DateTimeImmutable('now', $tz);
        $storms = [];

        foreach ($times as $i => $timeStr) {
            $dt   = new \DateTimeImmutable($timeStr, $tz);
            $code = (int) ($hourly['weather_code'][$i] ?? 0);

            if ($dt < $now || !in_array($code, [95, 96, 99])) continue;

            $storms[] = [
                'datetime'    => $dt,
                'code'        => $code,
                'label'       => self::WMO_LABELS[$code] ?? 'Orage',
                'precip'      => round((float) ($hourly['precipitation'][$i] ?? 0), 1),
                'precip_prob' => (int) ($hourly['precipitation_probability'][$i] ?? 0),
                'wind'        => round((float) ($hourly['wind_speed_10m'][$i] ?? 0)),
                'gusts'       => round((float) ($hourly['wind_gusts_10m'][$i] ?? 0)),
                'temp'        => round((float) ($hourly['temperature_2m'][$i] ?? 0), 1),
                'wind_dir'    => $this->degreesToCompass((int) ($hourly['wind_direction_10m'][$i] ?? 0)),
            ];
        }

        return $storms;
    }

    // =========================================================================
    // Graphique horaire 48h
    // =========================================================================

    // =========================================================================
    // Données horaires par jour (pour le graphique interactif)
    // =========================================================================

    private function buildDaysChartData(array $forecast, \DateTimeZone $tz): array
    {
        $hourly = $forecast['hourly'] ?? [];
        $times  = $hourly['time'] ?? [];
        $result = [];

        foreach ($times as $i => $timeStr) {
            $dt     = new \DateTimeImmutable($timeStr, $tz);
            $dayKey = $dt->format('Y-m-d');

            if (!isset($result[$dayKey])) {
                $result[$dayKey] = [
                    'labels' => [], 'temps' => [], 'feelsLike' => [],
                    'precip' => [], 'precipProb' => [], 'weatherCodes' => [],
                    'isDay'  => [], 'humidity' => [], 'clouds' => [], 'wind' => [],
                ];
            }

            $result[$dayKey]['labels'][]        = $dt->format('H\hi');
            $result[$dayKey]['temps'][]         = round((float) ($hourly['temperature_2m'][$i] ?? 0), 1);
            $result[$dayKey]['feelsLike'][]     = round((float) ($hourly['apparent_temperature'][$i] ?? 0), 1);
            $result[$dayKey]['precip'][]        = round((float) ($hourly['precipitation'][$i] ?? 0), 2);
            $result[$dayKey]['precipProb'][]    = (int) ($hourly['precipitation_probability'][$i] ?? 0);
            $result[$dayKey]['weatherCodes'][]  = (int) ($hourly['weather_code'][$i] ?? 0);
            $result[$dayKey]['isDay'][]         = (bool) ($hourly['is_day'][$i] ?? 1);
            $result[$dayKey]['humidity'][]      = (int) ($hourly['relative_humidity_2m'][$i] ?? 0);
            $result[$dayKey]['clouds'][]        = (int) ($hourly['cloud_cover'][$i] ?? 0);
            $result[$dayKey]['wind'][]          = round((float) ($hourly['wind_speed_10m'][$i] ?? 0));
        }

        return $result;
    }

    private function buildHourlyChart(array $forecast, \DateTimeZone $tz): array
    {
        $hourly  = $forecast['hourly'] ?? [];
        $times   = $hourly['time'] ?? [];
        $now     = new \DateTimeImmutable('now', $tz);
        $cutoff  = $now->modify('+48 hours');

        $labels = $temps = $feelsLike = $precip = $precipProb = $clouds = $isDay = $humidity = $weatherCodes = $wind = [];

        foreach ($times as $i => $timeStr) {
            $dt = new \DateTimeImmutable($timeStr, $tz);
            if ($dt < $now->modify('-30 minutes')) continue;
            if ($dt > $cutoff) break;

            $labels[]       = $dt->format('H\hi');
            $temps[]        = round((float) ($hourly['temperature_2m'][$i] ?? 0), 1);
            $feelsLike[]    = round((float) ($hourly['apparent_temperature'][$i] ?? 0), 1);
            $precip[]       = round((float) ($hourly['precipitation'][$i] ?? 0), 2);
            $precipProb[]   = (int) ($hourly['precipitation_probability'][$i] ?? 0);
            $clouds[]       = (int) ($hourly['cloud_cover'][$i] ?? 0);
            $isDay[]        = (bool) ($hourly['is_day'][$i] ?? 1);
            $humidity[]     = (int) ($hourly['relative_humidity_2m'][$i] ?? 0);
            $weatherCodes[] = (int) ($hourly['weather_code'][$i] ?? 0);
            $wind[]         = round((float) ($hourly['wind_speed_10m'][$i] ?? 0));
        }

        return compact('labels', 'temps', 'feelsLike', 'precip', 'precipProb', 'clouds', 'isDay', 'humidity', 'weatherCodes', 'wind');
    }

    // =========================================================================
    // Statistiques 14 jours
    // =========================================================================

    private function buildWeekStats(array $days): array
    {
        $maxTemp = null;
        $minTemp = null;
        $totalPrecip = 0.0;
        $totalSunshine = 0.0;
        $stormDays = $rainDays = $snowDays = $clearDays = 0;

        foreach ($days as $day) {
            if ($day['temp_max'] !== null) {
                $maxTemp = $maxTemp === null ? $day['temp_max'] : max($maxTemp, $day['temp_max']);
            }
            if ($day['temp_min'] !== null) {
                $minTemp = $minTemp === null ? $day['temp_min'] : min($minTemp, $day['temp_min']);
            }
            $totalPrecip   += $day['precip_sum'];
            $totalSunshine += $day['sunshine_hours'];

            match ($day['type']) {
                'storm'                 => $stormDays++,
                'snow'                  => $snowDays++,
                'heavy_rain', 'rain'    => $rainDays++,
                'clear', 'partly_cloudy' => $clearDays++,
                default                 => null,
            };
        }

        return [
            'max_temp'       => $maxTemp !== null ? round($maxTemp, 1) : null,
            'min_temp'       => $minTemp !== null ? round($minTemp, 1) : null,
            'total_precip'   => round($totalPrecip, 1),
            'sunshine_hours' => round($totalSunshine, 1),
            'storm_days'     => $stormDays,
            'rain_days'      => $rainDays,
            'snow_days'      => $snowDays,
            'clear_days'     => $clearDays,
            'days_count'     => count($days),
        ];
    }

    // =========================================================================
    // Phases de lune
    // =========================================================================

    private function computeMoonPhases(\DateTimeZone $tz): array
    {
        $now          = new \DateTimeImmutable('now', $tz);
        $currentPhase = $this->getMoonPhase($now);

        $milestones = [
            ['target' => 0.0,  'name' => 'new',           'label' => 'Nouvelle Lune',    'emoji' => '🌑'],
            ['target' => 0.25, 'name' => 'first_quarter', 'label' => 'Premier Quartier', 'emoji' => '🌓'],
            ['target' => 0.5,  'name' => 'full',          'label' => 'Pleine Lune',      'emoji' => '🌕'],
            ['target' => 0.75, 'name' => 'last_quarter',  'label' => 'Dernier Quartier', 'emoji' => '🌗'],
        ];

        $syn  = 29.53058770576;
        $next = [];
        foreach ($milestones as $milestone) {
            $daysUntil = $this->daysToNextPhase($currentPhase, $milestone['target']);
            for ($cycle = 0; $cycle < 1; $cycle++) {
                $d = $daysUntil + $cycle * $syn;
                $next[] = [
                    'name'       => $milestone['name'],
                    'label'      => $milestone['label'],
                    'emoji'      => $milestone['emoji'],
                    'date'       => $now->modify(sprintf('+%d days', (int) round($d))),
                    'days_until' => (int) round($d),
                ];
            }
        }

        usort($next, fn (array $a, array $b) => $a['days_until'] <=> $b['days_until']);

        return [
            'current_phase' => $currentPhase,
            'current_label' => $this->getMoonLabel($currentPhase),
            'current_emoji' => $this->getMoonEmoji($currentPhase),
            'illumination'  => $this->getMoonIllumination($currentPhase),
            'next'          => $next,
        ];
    }

    private function getMoonPhase(\DateTimeImmutable $date): float
    {
        $jd   = $date->getTimestamp() / 86400.0 + 2440587.5;
        $diff = $jd - 2451549.5754;
        $syn  = 29.53058770576;
        $p    = fmod($diff / $syn, 1.0);
        return $p < 0.0 ? $p + 1.0 : $p;
    }

    private function daysToNextPhase(float $current, float $target): float
    {
        $syn  = 29.53058770576;
        $diff = fmod($target - $current + 1.0, 1.0);
        if ($diff < 0.0085) $diff += 1.0;
        return $diff * $syn;
    }

    private function getMoonLabel(float $p): string
    {
        return match (true) {
            $p < 0.03 || $p > 0.97 => 'Nouvelle Lune',
            $p < 0.22              => 'Croissant croissant',
            $p < 0.28              => 'Premier Quartier',
            $p < 0.47              => 'Gibbeuse croissante',
            $p < 0.53              => 'Pleine Lune',
            $p < 0.72              => 'Gibbeuse décroissante',
            $p < 0.78              => 'Dernier Quartier',
            default                => 'Croissant décroissant',
        };
    }

    private function getMoonEmoji(float $p): string
    {
        return match (true) {
            $p < 0.0625 => '🌑', $p < 0.1875 => '🌒', $p < 0.3125 => '🌓', $p < 0.4375 => '🌔',
            $p < 0.5625 => '🌕', $p < 0.6875 => '🌖', $p < 0.8125 => '🌗', $p < 0.9375 => '🌘',
            default     => '🌑',
        };
    }

    private function getMoonIllumination(float $p): int
    {
        return (int) round(abs(sin($p * M_PI)) * 100);
    }

    // =========================================================================
    // Utilitaires
    // =========================================================================

    private function degreesToCompass(int $deg): string
    {
        $dirs = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSO', 'SO', 'OSO', 'O', 'ONO', 'NO', 'NNO'];
        return $dirs[(int) round($deg / 22.5) % 16];
    }

    private function uvLabel(float $uv): string
    {
        return match (true) {
            $uv <= 2  => 'Faible',
            $uv <= 5  => 'Modéré',
            $uv <= 7  => 'Élevé',
            $uv <= 10 => 'Très élevé',
            default   => 'Extrême',
        };
    }

    private function uvColor(float $uv): string
    {
        return match (true) {
            $uv <= 2  => '#22c55e',
            $uv <= 5  => '#eab308',
            $uv <= 7  => '#f97316',
            $uv <= 10 => '#ef4444',
            default   => '#a855f7',
        };
    }
}
