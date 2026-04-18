<?php

namespace App\Service;

use App\Exception\ServiceNotConfiguredException;
use App\Repository\SettingRepository;

/**
 * Fournit l'accès aux paramètres stockés en BDD (table `setting`).
 * Cache en mémoire pendant la durée de la requête.
 */
class ConfigService
{
    /** @var array<string, ?string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly SettingRepository $settings,
    ) {}

    public function get(string $key): ?string
    {
        $this->loadCache();
        $value = $this->cache[$key] ?? null;
        return $value !== '' ? $value : null;
    }

    /**
     * Retourne la valeur ou jette ServiceNotConfiguredException si absente.
     */
    public function require(string $key, string $service): string
    {
        $value = $this->get($key);
        if ($value === null) {
            throw new ServiceNotConfiguredException($service, $key);
        }
        return $value;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function set(string $key, ?string $value): void
    {
        $this->settings->set($key, $value);
        $this->cache = null;
    }

    /**
     * Invalide le cache — utile après un update en masse (wizard, admin).
     */
    public function invalidate(): void
    {
        $this->cache = null;
    }

    private function loadCache(): void
    {
        if ($this->cache === null) {
            $this->cache = $this->settings->getAll();
        }
    }
}
