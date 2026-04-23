<?php

namespace App\Controller;

use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;

/**
 * Admin-only page to edit service configuration without replaying the full
 * setup wizard. Every field is pre-filled from the `setting` DB table; on
 * save, the ConfigService + HealthService caches are invalidated so the
 * new values take effect on the next request.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/settings', name: 'admin_settings_')]
class AdminSettingsController extends AbstractController
{
    /**
     * Grouped field declarations. Each group maps to a card in the template.
     * @var array<string, list<array{key: string, type: string, label: string, placeholder?: string}>>
     */
    private const FIELDS = [
        'tmdb' => [
            ['key' => 'tmdb_api_key', 'type' => 'password', 'label' => 'Clé API v3', 'placeholder' => '7a2f4…'],
        ],
        'radarr' => [
            ['key' => 'radarr_url',     'type' => 'text',     'label' => 'URL',     'placeholder' => 'http://host.docker.internal:7878'],
            ['key' => 'radarr_api_key', 'type' => 'password', 'label' => 'Clé API'],
        ],
        'sonarr' => [
            ['key' => 'sonarr_url',     'type' => 'text',     'label' => 'URL',     'placeholder' => 'http://host.docker.internal:8989'],
            ['key' => 'sonarr_api_key', 'type' => 'password', 'label' => 'Clé API'],
        ],
        'prowlarr' => [
            ['key' => 'prowlarr_url',     'type' => 'text',     'label' => 'URL',     'placeholder' => 'http://host.docker.internal:9696'],
            ['key' => 'prowlarr_api_key', 'type' => 'password', 'label' => 'Clé API'],
        ],
        'jellyseerr' => [
            ['key' => 'jellyseerr_url',     'type' => 'text',     'label' => 'URL',     'placeholder' => 'http://host.docker.internal:5055'],
            ['key' => 'jellyseerr_api_key', 'type' => 'password', 'label' => 'Clé API'],
        ],
        'qbittorrent' => [
            ['key' => 'qbittorrent_url',      'type' => 'text',     'label' => 'URL',           'placeholder' => 'http://host.docker.internal:8080'],
            ['key' => 'qbittorrent_user',     'type' => 'text',     'label' => 'Utilisateur'],
            ['key' => 'qbittorrent_password', 'type' => 'password', 'label' => 'Mot de passe'],
        ],
        'gluetun' => [
            ['key' => 'gluetun_url',      'type' => 'text',     'label' => 'URL'],
            ['key' => 'gluetun_api_key',  'type' => 'password', 'label' => 'Clé API (si protégé)'],
            ['key' => 'gluetun_protocol', 'type' => 'text',     'label' => 'Protocole (openvpn/wireguard)'],
        ],
    ];

    private const SERVICE_LABELS = [
        'tmdb'        => 'TMDb',
        'radarr'      => 'Radarr',
        'sonarr'      => 'Sonarr',
        'prowlarr'    => 'Prowlarr',
        'jellyseerr'  => 'Jellyseerr',
        'qbittorrent' => 'qBittorrent',
        'gluetun'     => 'Gluetun',
    ];

    /**
     * Internal features — aggregated pages without their own API key/URL.
     * Same visibility-toggle pattern as SERVICE_LABELS, but no fields to edit
     * and no "Tester la connexion" button.
     *
     * @var array<string, array{label: string, subtitle: string}>
     */
    private const INTERNAL_FEATURES = [
        'calendar' => [
            'label'    => 'Calendrier',
            'subtitle' => 'Sorties films + épisodes agrégées (Radarr + Sonarr)',
        ],
    ];

    /**
     * Display preferences — stored in DB under `display_*` keys, surfaced as
     * selects/switches/swatches in the "Affichage" section. Source of truth
     * for defaults and allowed values — DisplayPreferencesService reads the
     * same constants at runtime to validate incoming values.
     *
     * @var array<string, array{label: string, type: string, default: string, options?: array<string, string>, help?: string}>
     */
    public const DISPLAY_OPTIONS = [
        'display_home_page' => [
            'label'   => 'Page d\'accueil par défaut',
            'type'    => 'select',
            'default' => 'dashboard',
            'options' => [
                'dashboard'   => 'Tableau de bord',
                'discovery'   => 'Découverte (TMDb)',
                'films'       => 'Films',
                'series'      => 'Séries',
                'qbittorrent' => 'Téléchargements',
                'last'        => 'Dernière page visitée',
            ],
            'help' => 'La page que Prismarr affiche quand vous vous connectez.',
        ],
        'display_toasts' => [
            'label'   => 'Notifications (toasts)',
            'type'    => 'switch',
            'default' => '1',
            'help'    => 'Afficher les bulles fugaces en bas d\'écran (fin de téléchargement, erreurs).',
        ],
        'display_timezone' => [
            'label'   => 'Fuseau horaire',
            'type'    => 'timezone',
            'default' => 'Europe/Paris',
            'help'    => 'Utilisé pour afficher toutes les dates/heures.',
        ],
        'display_date_format' => [
            'label'   => 'Format de date',
            'type'    => 'select',
            'default' => 'fr',
            'options' => [
                'fr'  => 'Français (21/04/2026)',
                'us'  => 'Américain (Apr 21, 2026)',
                'iso' => 'ISO (2026-04-21)',
            ],
        ],
        'display_time_format' => [
            'label'   => 'Format d\'heure',
            'type'    => 'select',
            'default' => '24h',
            'options' => [
                '24h' => '24h (14:30)',
                '12h' => '12h (2:30 PM)',
            ],
        ],
        'display_theme_color' => [
            'label'   => 'Couleur principale',
            'type'    => 'color',
            'default' => 'indigo',
            'options' => [
                'indigo' => '#6366f1',
                'red'    => '#ef4444',
                'green'  => '#22c55e',
                'orange' => '#f59e0b',
                'pink'   => '#ec4899',
                'blue'   => '#3b82f6',
            ],
        ],
        'display_qbit_refresh' => [
            'label'   => 'Rafraîchissement qBittorrent',
            'type'    => 'select',
            'default' => '2',
            'options' => [
                '1'  => '1 seconde (temps réel)',
                '2'  => '2 secondes (défaut)',
                '5'  => '5 secondes',
                '10' => '10 secondes (économe)',
                '0'  => 'Désactivé',
            ],
            'help' => 'Fréquence du polling automatique sur la page Téléchargements.',
        ],
        'display_ui_density' => [
            'label'   => 'Densité de l\'interface',
            'type'    => 'select',
            'default' => 'comfortable',
            'options' => [
                'comfortable' => 'Confortable (défaut)',
                'compact'     => 'Compacte',
            ],
        ],
    ];

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly ConfigService $config,
        private readonly HealthService $health,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')]
        private readonly AdapterInterface $appCache,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir = '',
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'prod',
    ) {}

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_settings', (string) $request->request->get('_csrf_token'))) {
                $errors[] = 'Jeton CSRF invalide, réessayez.';
            }

            if ($errors === []) {
                $this->saveSubmitted($request);
                // POST/Redirect/GET so the flash shows and refreshes work cleanly.
                return $this->redirectToRoute('admin_settings_index');
            }
        }

        return $this->render('admin/settings.html.twig', [
            'groups'             => self::FIELDS,
            'service_labels'     => self::SERVICE_LABELS,
            'values'             => $this->loadValues(),
            'sidebar_visibility' => $this->loadSidebarVisibility(),
            'internal_features'  => self::INTERNAL_FEATURES,
            'display_options'    => self::DISPLAY_OPTIONS,
            'display_values'     => $this->loadDisplayValues(),
            'timezones'          => \DateTimeZone::listIdentifiers(),
            'system_info'        => $this->systemInfo(),
            'export_counts'      => $this->exportCounts(),
            'errors'             => $errors,
        ]);
    }

    /**
     * Read-only snapshot of the runtime environment for the "À propos"
     * section. Everything is computed on render — no caching — since the
     * settings page is visited rarely enough that the cost is negligible.
     */
    private function systemInfo(): array
    {
        $projectDir = $this->projectDir ?: ($_SERVER['KERNEL_PROJECT_DIR'] ?? '');
        $dbPath     = $projectDir . '/var/data/prismarr.db';

        // Library counts are best-effort — if Radarr/Sonarr are down we
        // render "—" instead of crashing the whole page.
        $films  = null;
        $series = null;
        try {
            /** @var RadarrClient $radarr */
            $radarr = $this->container->get(RadarrClient::class);
            $films  = count($radarr->getMovies());
        } catch (\Throwable) {}
        try {
            /** @var SonarrClient $sonarr */
            $sonarr = $this->container->get(SonarrClient::class);
            $series = count($sonarr->getSeries());
        } catch (\Throwable) {}

        /** @var UserRepository $users */
        $users = $this->container->get(UserRepository::class);
        $userCount = null;
        try {
            $userCount = $users->count([]);
        } catch (\Throwable) {}

        return [
            'prismarr_version' => $_ENV['PRISMARR_VERSION'] ?? getenv('PRISMARR_VERSION') ?: '1.0.0-dev',
            'symfony_version'  => Kernel::VERSION,
            'php_version'      => PHP_VERSION,
            'sapi'             => PHP_SAPI,
            'environment'      => $this->environment,
            'db_path'          => $dbPath,
            'db_size'          => is_file($dbPath) ? filesize($dbPath) : 0,
            'avatars_dir'      => $projectDir . '/var/data/avatars',
            'user_count'       => $userCount,
            'film_count'       => $films,
            'series_count'     => $series,
            'server_time'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'timezone'         => date_default_timezone_get(),
        ];
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            RadarrClient::class    => RadarrClient::class,
            SonarrClient::class    => SonarrClient::class,
            UserRepository::class  => UserRepository::class,
        ]);
    }

    #[Route('/test/{service}', name: 'test', methods: ['POST'])]
    public function test(string $service): JsonResponse
    {
        if (!isset(self::SERVICE_LABELS[$service])) {
            return new JsonResponse(['ok' => false, 'error' => 'Service inconnu'], 400);
        }

        try {
            $this->health->invalidate($service);
            $ok = $this->health->isHealthy($service);
        } catch (\Throwable $e) {
            $this->logger->warning('AdminSettings test failed for {service}: {message}', [
                'service' => $service,
                'message' => $e->getMessage(),
            ]);
            return new JsonResponse(['ok' => false, 'error' => 'Service injoignable']);
        }

        return new JsonResponse([
            'ok'      => $ok,
            'service' => self::SERVICE_LABELS[$service],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function loadValues(): array
    {
        $out = [];
        foreach (self::FIELDS as $group) {
            foreach ($group as $field) {
                $out[$field['key']] = (string) ($this->config->get($field['key']) ?? '');
            }
        }
        return $out;
    }

    /**
     * @return array<string, bool>  id => visible (true by default)
     */
    private function loadSidebarVisibility(): array
    {
        $out = [];
        $all = array_merge(array_keys(self::SERVICE_LABELS), array_keys(self::INTERNAL_FEATURES));
        foreach ($all as $id) {
            $out[$id] = $this->config->get('sidebar_hide_' . $id) !== '1';
        }
        return $out;
    }

    /**
     * @return array<string, string>  display_* key => current or default value
     */
    private function loadDisplayValues(): array
    {
        $out = [];
        foreach (self::DISPLAY_OPTIONS as $key => $spec) {
            $stored = $this->config->get($key);
            $out[$key] = $stored !== null && $stored !== '' ? $stored : $spec['default'];
        }
        return $out;
    }

    private function saveSubmitted(Request $request): void
    {
        $payload = [];
        foreach (self::FIELDS as $group) {
            foreach ($group as $field) {
                $value = trim((string) $request->request->get($field['key'], ''));
                $payload[$field['key']] = $value !== '' ? $value : null;
            }
        }

        // Sidebar visibility — one checkbox per service/feature. An unchecked
        // box is NOT sent by the browser, so we consider it hidden; a checked
        // one clears the hide flag.
        $all = array_merge(array_keys(self::SERVICE_LABELS), array_keys(self::INTERNAL_FEATURES));
        foreach ($all as $id) {
            $visible = $request->request->has('sidebar_visible_' . $id);
            $payload['sidebar_hide_' . $id] = $visible ? null : '1';
        }

        // Display preferences — only accept values from the declared allow-list
        // (selects/colors) or '1'/'0' for switches. Anything else is dropped
        // silently and the default kicks back in on next read.
        foreach (self::DISPLAY_OPTIONS as $key => $spec) {
            $raw = trim((string) $request->request->get($key, ''));
            $payload[$key] = $this->normalizeDisplayValue($spec, $raw);
        }

        $this->settings->setMany($payload);
        $this->config->invalidate();
        $this->health->invalidate();
        // Purge TMDb/Radarr/Sonarr response cache so data fetched with
        // the previous config doesn't linger up to an hour.
        $this->appCache->clear();
        $this->addFlash('success', 'Configuration enregistrée.');
    }

    /**
     * Non-sensitive settings only — keys containing secrets (api_key,
     * password) are filtered out so the exported JSON is safe to share
     * or commit to a private dotfiles repo.
     */
    private const EXPORT_SENSITIVE_PATTERNS = ['api_key', 'password', 'secret'];

    /**
     * @return array{safe: int, skipped: int}
     */
    private function exportCounts(): array
    {
        $safe = 0; $skipped = 0;
        foreach ($this->settings->findAll() as $s) {
            if ($this->isSensitiveKey($s->getName())) { $skipped++; } else { $safe++; }
        }
        return ['safe' => $safe, 'skipped' => $skipped];
    }

    #[Route('/reset-display', name: 'reset_display', methods: ['POST'])]
    public function resetDisplay(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_settings_reset_display', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_settings_index');
        }

        // Null value = delete the row, which makes the next read fall back
        // to the declared default in DISPLAY_OPTIONS.
        $payload = [];
        foreach (array_keys(self::DISPLAY_OPTIONS) as $key) {
            $payload[$key] = null;
        }
        $this->settings->setMany($payload);
        $this->config->invalidate();
        $this->health->invalidate();
        $this->appCache->clear();

        $this->addFlash('success', 'Préférences d\'affichage réinitialisées aux valeurs par défaut.');
        return $this->redirectToRoute('admin_settings_index');
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(): Response
    {
        $all = $this->settings->findAll();
        $payload = [
            'prismarr_export_version' => 1,
            'exported_at'             => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'settings'                => [],
        ];

        foreach ($all as $setting) {
            $name = $setting->getName();
            if ($this->isSensitiveKey($name)) {
                continue;
            }
            $payload['settings'][$name] = $setting->getValue();
        }

        ksort($payload['settings']);

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return new JsonResponse(['error' => 'Encodage impossible.'], 500);
        }

        return new Response(
            $json,
            Response::HTTP_OK,
            [
                'Content-Type'        => 'application/json',
                'Content-Disposition' => 'attachment; filename="prismarr-config-' . date('Y-m-d') . '.json"',
            ],
        );
    }

    #[Route('/import', name: 'import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_settings_import', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_settings_index');
        }

        $file = $request->files->get('config');
        if (!$file || !$file->isValid()) {
            $this->addFlash('error', 'Aucun fichier reçu.');
            return $this->redirectToRoute('admin_settings_index');
        }

        if ($file->getSize() > 64_000) {
            $this->addFlash('error', 'Fichier trop volumineux (max 64 Ko).');
            return $this->redirectToRoute('admin_settings_index');
        }

        $raw = @file_get_contents($file->getPathname());
        if ($raw === false) {
            $this->addFlash('error', 'Lecture du fichier impossible.');
            return $this->redirectToRoute('admin_settings_index');
        }

        try {
            $payload = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->addFlash('error', 'JSON invalide : ' . $e->getMessage());
            return $this->redirectToRoute('admin_settings_index');
        }

        if (!is_array($payload) || ($payload['prismarr_export_version'] ?? 0) !== 1 || !is_array($payload['settings'] ?? null)) {
            $this->addFlash('error', 'Format non reconnu (version export v1 attendue).');
            return $this->redirectToRoute('admin_settings_index');
        }

        $applied = 0;
        $skipped = 0;
        $toApply = [];
        foreach ($payload['settings'] as $name => $value) {
            if (!is_string($name) || $name === '') {
                $skipped++;
                continue;
            }
            // Never let an import overwrite secrets — even if someone
            // managed to craft a payload with them, we refuse silently
            // so a compromised export file can't leak into DB.
            if ($this->isSensitiveKey($name)) {
                $skipped++;
                continue;
            }
            if ($value !== null && !is_scalar($value)) {
                $skipped++;
                continue;
            }
            $toApply[$name] = $value === null ? null : (string) $value;
            $applied++;
        }

        if ($applied > 0) {
            $this->settings->setMany($toApply);
            $this->config->invalidate();
            $this->health->invalidate();
            $this->appCache->clear();
        }

        $this->addFlash(
            'success',
            sprintf('%d réglage%s importé%s, %d ignoré%s.', $applied, $applied > 1 ? 's' : '', $applied > 1 ? 's' : '', $skipped, $skipped > 1 ? 's' : ''),
        );

        return $this->redirectToRoute('admin_settings_index');
    }

    private function isSensitiveKey(string $name): bool
    {
        $lower = strtolower($name);
        foreach (self::EXPORT_SENSITIVE_PATTERNS as $p) {
            if (str_contains($lower, $p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{label: string, type: string, default: string, options?: array<string, string>, help?: string} $spec
     */
    private function normalizeDisplayValue(array $spec, string $raw): ?string
    {
        if ($spec['type'] === 'switch') {
            return $raw === '1' ? '1' : '0';
        }

        if ($spec['type'] === 'timezone') {
            return in_array($raw, \DateTimeZone::listIdentifiers(), true) ? $raw : null;
        }

        if (isset($spec['options']) && isset($spec['options'][$raw])) {
            return $raw;
        }

        // Unknown / blanked value → null so loadDisplayValues() falls back to default.
        return null;
    }
}
