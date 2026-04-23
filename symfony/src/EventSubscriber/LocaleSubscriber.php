<?php

namespace App\EventSubscriber;

use App\Service\DisplayPreferencesService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolves the active locale on every main request.
 *
 * Priority order:
 *   1. `?_locale=xx` query param (temporary override, never persisted)
 *   2. `prismarr_locale` cookie (set by the topbar switcher)
 *   3. Admin preference `display_language` from the DB
 *   4. Hard-coded `fr` fallback
 *
 * We accept only whitelisted locales to avoid breaking Twig/Translator when
 * someone forges a cookie or crafts a `?_locale=zz` URL — unknown values
 * silently fall back to the next step.
 */
class LocaleSubscriber implements EventSubscriberInterface
{
    public const COOKIE_NAME = 'prismarr_locale';
    public const SUPPORTED   = ['fr', 'en'];
    public const FALLBACK    = 'fr';

    public function __construct(
        private readonly DisplayPreferencesService $prefs,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priority 20 — run before Symfony's own LocaleListener (priority 16)
        // and well before LastVisitedRouteSubscriber on RESPONSE.
        return [KernelEvents::REQUEST => ['onKernelRequest', 20]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $locale = $this->pickLocale($request->query->get('_locale'))
            ?? $this->pickLocale($request->cookies->get(self::COOKIE_NAME))
            ?? $this->pickLocale($this->safePrefLanguage())
            ?? self::FALLBACK;

        $request->setLocale($locale);
    }

    private function pickLocale(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return in_array($value, self::SUPPORTED, true) ? $value : null;
    }

    /**
     * Reading DisplayPreferencesService can hit the DB via ConfigService —
     * we wrap it so a BDD outage (e.g. during setup wizard) never bubbles
     * up as a 500 on unrelated pages.
     */
    private function safePrefLanguage(): ?string
    {
        try {
            return $this->prefs->getLanguage();
        } catch (\Throwable) {
            return null;
        }
    }
}
