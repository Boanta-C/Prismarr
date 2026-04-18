<?php

namespace App\EventSubscriber;

use App\Controller\SetupController;
use App\Repository\SettingRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Redirige vers le wizard de setup tant qu'il n'a pas été finalisé
 * (table `user` vide ou flag `setup.completed` absent).
 */
class SetupRedirectSubscriber implements EventSubscriberInterface
{
    private const PATH_WHITELIST_PREFIXES = [
        '/setup',
        '/login',
        '/logout',
        '/_profiler',
        '/_wdt',
        '/_error',
        '/assets',
        '/static',
        '/img',
        '/favicon',
    ];

    private ?bool $setupDone = null;

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        foreach (self::PATH_WHITELIST_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        if ($this->isSetupDone()) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urls->generate('app_setup_root')
        ));
    }

    private function isSetupDone(): bool
    {
        if ($this->setupDone !== null) {
            return $this->setupDone;
        }

        try {
            return $this->setupDone = $this->settings->get(SetupController::SETUP_DONE_KEY) === '1';
        } catch (\Throwable) {
            // Schéma pas encore appliqué : on laisse passer pour que `make init` puisse créer les tables.
            return $this->setupDone = true;
        }
    }
}
