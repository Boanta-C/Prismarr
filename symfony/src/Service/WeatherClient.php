<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WeatherClient
{
    private const API_URL = 'https://api.open-meteo.com/v1/forecast';
    private const CACHE_TTL = 1800; // 30 minutes

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $latitude,
        private readonly string $longitude,
        private readonly string $locationName,
    ) {}

    public function getForecast(): array
    {
        $key = 'weather_v2_' . str_replace('.', '_', $this->latitude) . '_' . str_replace('.', '_', $this->longitude);

        return $this->cache->get($key, function (ItemInterface $item): array {
            $result = $this->fetchForecast();
            // Si erreur, cache court (30s) pour retry rapide
            if (empty($result) || isset($result['_error'])) {
                $item->expiresAfter(30);
            } else {
                $item->expiresAfter(self::CACHE_TTL);
            }
            return $result;
        });
    }

    public function getLocationName(): string
    {
        return $this->locationName;
    }

    public function getCacheTtl(): int
    {
        return self::CACHE_TTL;
    }

    public function invalidateCache(): void
    {
        $key = 'weather_v2_' . str_replace('.', '_', $this->latitude) . '_' . str_replace('.', '_', $this->longitude);
        $this->cache->delete($key);
    }

    private function fetchForecast(): array
    {
        try {
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query'   => [
                    'latitude'   => $this->latitude,
                    'longitude'  => $this->longitude,
                    'daily'      => implode(',', [
                        'weather_code',
                        'temperature_2m_max', 'temperature_2m_min',
                        'apparent_temperature_max', 'apparent_temperature_min',
                        'precipitation_sum', 'precipitation_hours',
                        'rain_sum', 'showers_sum', 'snowfall_sum',
                        'precipitation_probability_max',
                        'wind_speed_10m_max', 'wind_gusts_10m_max', 'wind_direction_10m_dominant',
                        'sunrise', 'sunset',
                        'uv_index_max',
                        'sunshine_duration', 'daylight_duration',
                    ]),
                    'hourly'     => implode(',', [
                        'weather_code',
                        'temperature_2m', 'apparent_temperature',
                        'relative_humidity_2m', 'dew_point_2m',
                        'precipitation', 'precipitation_probability',
                        'rain', 'showers', 'snowfall',
                        'surface_pressure',
                        'cloud_cover',
                        'visibility',
                        'wind_speed_10m', 'wind_gusts_10m', 'wind_direction_10m',
                        'uv_index',
                        'is_day',
                    ]),
                    'forecast_days' => 14,
                    'timezone'      => 'Europe/Paris',
                ],
                'timeout' => 10,
            ]);

            $data = $response->toArray();
            $data['_fetched_at'] = time();
            return $data;
        } catch (\Throwable $e) {
            $this->logger->error('[WeatherClient] Échec fetch Open-Meteo : ' . $e->getMessage());
            return ['_error' => $e->getMessage()];
        }
    }
}
