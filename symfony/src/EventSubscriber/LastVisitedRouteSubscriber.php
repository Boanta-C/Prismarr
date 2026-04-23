<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tracks the most-recently visited landing route in a long-lived cookie so
 * HomeController can land the user back on it when their
 * `display_home_page` preference is set to "last visited".
 *
 * We only remember routes the user actively navigates to (GET main requests
 * with a named route), and explicitly skip the home redirect itself, login,
 * logout, setup wizard, and API endpoints — none of those make sense as a
 * "last visited" destination.
 */
class LastVisitedRouteSubscriber implements EventSubscriberInterface
{
    public const COOKIE_NAME = 'prismarr_last_route';

    private const ROUTE_BLOCKLIST = [
        'app_home',
        'app_login',
        'app_logout',
        'api_health',
        'app_calendrier_ical',
    ];

    private const ROUTE_PREFIX_BLOCKLIST = [
        'app_setup_',
        'admin_settings_',
        'api_',                          // /api/health, /api/health/services, etc. — JSON endpoints
        'app_profile_avatar_',           // avatar fetch + upload endpoints
        'app_qbittorrent_api_',          // qBit poll-summary and friends
        '_',                             // Symfony internals (_profiler, _wdt, _error)
    ];

    public static function getSubscribedEvents(): array
    {
        // Run after the kernel produces its response so we can attach a cookie.
        return [KernelEvents::RESPONSE => ['onKernelResponse', -20]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('GET')) {
            return;
        }

        // Skip non-navigation requests (XHR, Turbo frames, asset fetches).
        if ($request->isXmlHttpRequest() || $request->headers->has('Turbo-Frame')) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (!is_string($route) || $route === '') {
            return;
        }

        if (in_array($route, self::ROUTE_BLOCKLIST, true)) {
            return;
        }
        foreach (self::ROUTE_PREFIX_BLOCKLIST as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return;
            }
        }

        // Only cache responses that actually render something to the user.
        $status = $event->getResponse()->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return;
        }

        $event->getResponse()->headers->setCookie(
            Cookie::create(self::COOKIE_NAME)
                ->withValue($route)
                ->withPath('/')
                ->withExpires(new \DateTimeImmutable('+30 days'))
                ->withSameSite(Cookie::SAMESITE_LAX)
                ->withSecure($request->isSecure())
                ->withHttpOnly(true),
        );
    }
}
