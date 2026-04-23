<?php

namespace App\Controller;

use App\EventSubscriber\LocaleSubscriber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Per-user UI language switcher: drops a long-lived cookie
 * `prismarr_locale` that takes precedence over the admin-level
 * `display_language` setting. Reset by clicking the default entry
 * in the topbar dropdown.
 */
class LocaleController extends AbstractController
{
    #[Route('/locale/{code}', name: 'app_locale_switch', methods: ['POST'], requirements: ['code' => '[a-z]{2}'])]
    public function switch(string $code, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('locale_switch', (string) $request->request->get('_csrf_token'))) {
            return $this->redirectBack($request);
        }

        $response = $this->redirectBack($request);

        if (in_array($code, LocaleSubscriber::SUPPORTED, true)) {
            $response->headers->setCookie(
                Cookie::create(LocaleSubscriber::COOKIE_NAME)
                    ->withValue($code)
                    ->withPath('/')
                    ->withExpires(new \DateTimeImmutable('+1 year'))
                    ->withSameSite(Cookie::SAMESITE_LAX)
                    ->withSecure($request->isSecure())
                    ->withHttpOnly(false), // JS reads it to pre-select the dropdown
            );
        }

        return $response;
    }

    #[Route('/locale/reset', name: 'app_locale_reset', methods: ['POST'])]
    public function reset(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('locale_switch', (string) $request->request->get('_csrf_token'))) {
            return $this->redirectBack($request);
        }

        $response = $this->redirectBack($request);
        $response->headers->clearCookie(LocaleSubscriber::COOKIE_NAME, '/');

        return $response;
    }

    private function redirectBack(Request $request): RedirectResponse
    {
        $ref = $request->headers->get('referer');

        // Only trust same-host referers — otherwise redirect to home so an
        // attacker cannot forge a cross-site logout redirect.
        if (is_string($ref) && $ref !== '') {
            $parsed = parse_url($ref);
            if (isset($parsed['host']) && $parsed['host'] === $request->getHost()) {
                return new RedirectResponse($ref);
            }
        }

        return $this->redirectToRoute('app_home');
    }
}
