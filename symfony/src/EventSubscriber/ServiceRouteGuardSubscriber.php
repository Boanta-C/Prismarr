<?php

namespace App\EventSubscriber;

use App\Service\ConfigService;
use App\Service\HealthService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Deux niveaux de garde pour les sections services :
 *   1. Si le service n'est PAS configuré (clé manquante en BDD) → redirect wizard.
 *   2. Si le service est configuré mais INJOIGNABLE → redirect vers l'index de la
 *      section (qui affiche le bandeau) — évite d'atterrir sur une sous-page cassée.
 *
 * Le health-check est caché par-process via HealthService (1 ping par worker).
 * Les routes index elles-mêmes sont exemptées du health-check (elles gèrent leur
 * propre bandeau, sinon on ferait une boucle de redirect).
 */
class ServiceRouteGuardSubscriber implements EventSubscriberInterface
{
    /**
     * @var array<string, array{service: string, service_id: string, keys: list<string>, wizard: string, index: string}>
     */
    private const RULES = [
        'radarr_'           => ['service' => 'Radarr',      'service_id' => 'radarr',      'keys' => ['radarr_api_key', 'radarr_url'],         'wizard' => 'app_setup_managers',  'index' => 'app_media_films'],
        'app_media_films'   => ['service' => 'Radarr',      'service_id' => 'radarr',      'keys' => ['radarr_api_key', 'radarr_url'],         'wizard' => 'app_setup_managers',  'index' => 'app_media_films'],
        'app_media_radarr'  => ['service' => 'Radarr',      'service_id' => 'radarr',      'keys' => ['radarr_api_key', 'radarr_url'],         'wizard' => 'app_setup_managers',  'index' => 'app_media_films'],
        'sonarr_'           => ['service' => 'Sonarr',      'service_id' => 'sonarr',      'keys' => ['sonarr_api_key', 'sonarr_url'],         'wizard' => 'app_setup_managers',  'index' => 'app_media_series'],
        'app_media_series'  => ['service' => 'Sonarr',      'service_id' => 'sonarr',      'keys' => ['sonarr_api_key', 'sonarr_url'],         'wizard' => 'app_setup_managers',  'index' => 'app_media_series'],
        'app_media_sonarr'  => ['service' => 'Sonarr',      'service_id' => 'sonarr',      'keys' => ['sonarr_api_key', 'sonarr_url'],         'wizard' => 'app_setup_managers',  'index' => 'app_media_series'],
        'prowlarr_'         => ['service' => 'Prowlarr',    'service_id' => 'prowlarr',    'keys' => ['prowlarr_api_key', 'prowlarr_url'],     'wizard' => 'app_setup_indexers',  'index' => 'prowlarr_index'],
        'jellyseerr_'       => ['service' => 'Jellyseerr',  'service_id' => 'jellyseerr',  'keys' => ['jellyseerr_api_key', 'jellyseerr_url'], 'wizard' => 'app_setup_indexers',  'index' => 'jellyseerr_index'],
        'qbittorrent_'      => ['service' => 'qBittorrent', 'service_id' => 'qbittorrent', 'keys' => ['qbittorrent_url', 'qbittorrent_user'],  'wizard' => 'app_setup_downloads', 'index' => 'app_qbittorrent_index'],
        'app_qbittorrent'   => ['service' => 'qBittorrent', 'service_id' => 'qbittorrent', 'keys' => ['qbittorrent_url', 'qbittorrent_user'],  'wizard' => 'app_setup_downloads', 'index' => 'app_qbittorrent_index'],
        'tmdb_'             => ['service' => 'TMDb',        'service_id' => 'tmdb',        'keys' => ['tmdb_api_key'],                         'wizard' => 'app_setup_tmdb',      'index' => 'tmdb_index'],
    ];

    public function __construct(
        private readonly ConfigService $config,
        private readonly HealthService $health,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priorité 15 : après SetupRedirectSubscriber (prio 20), avant le firewall Symfony.
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 15],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!is_string($route) || $route === '') {
            return;
        }

        $rule = $this->matchRule($route);
        if ($rule === null) {
            return;
        }

        // 1. Service pas configuré → wizard
        foreach ($rule['keys'] as $key) {
            if (!$this->config->has($key)) {
                $this->flash($event, sprintf('Configurez %s pour accéder à cette section.', $rule['service']));
                $event->setResponse(new RedirectResponse($this->urls->generate($rule['wizard'])));
                return;
            }
        }

        // 2. Service configuré mais inaccessible → redirect vers index de section
        //    (on skip si on EST déjà sur l'index, qui a son propre bandeau).
        if ($route !== $rule['index'] && !$this->health->isHealthy($rule['service_id'])) {
            $event->setResponse(new RedirectResponse($this->urls->generate($rule['index'])));
        }
    }

    /**
     * @return array{service: string, service_id: string, keys: list<string>, wizard: string, index: string}|null
     */
    private function matchRule(string $route): ?array
    {
        foreach (self::RULES as $prefix => $rule) {
            if (str_starts_with($route, $prefix)) {
                return $rule;
            }
        }
        return null;
    }

    private function flash(RequestEvent $event, string $message): void
    {
        $session = $event->getRequest()->hasSession() ? $event->getRequest()->getSession() : null;
        if ($session !== null && method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add('warning', $message);
        }
    }
}
