<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\LocaleSubscriber;
use App\Service\DisplayPreferencesService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class LocaleSubscriberTest extends TestCase
{
    private function event(Request $request, bool $mainRequest = true): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent(
            $kernel,
            $request,
            $mainRequest ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
        );
    }

    private function subscriber(?string $displayLanguage = null): LocaleSubscriber
    {
        $prefs = $this->createMock(DisplayPreferencesService::class);
        $prefs->method('getLanguage')->willReturn($displayLanguage ?? 'fr');

        return new LocaleSubscriber($prefs);
    }

    public function testSubRequestIsIgnored(): void
    {
        $request = Request::create('/');
        $request->setLocale('xx');
        $event = $this->event($request, mainRequest: false);

        ($this->subscriber())->onKernelRequest($event);

        $this->assertSame('xx', $request->getLocale());
    }

    public function testQueryParamWinsOverEverything(): void
    {
        $request = Request::create('/?_locale=en');
        $request->cookies->set(LocaleSubscriber::COOKIE_NAME, 'fr');
        $event = $this->event($request);

        ($this->subscriber('fr'))->onKernelRequest($event);

        $this->assertSame('en', $request->getLocale());
    }

    public function testCookieWinsOverPreference(): void
    {
        $request = Request::create('/');
        $request->cookies->set(LocaleSubscriber::COOKIE_NAME, 'en');
        $event = $this->event($request);

        ($this->subscriber('fr'))->onKernelRequest($event);

        $this->assertSame('en', $request->getLocale());
    }

    public function testPreferenceUsedWhenNoCookie(): void
    {
        $request = Request::create('/');
        $event = $this->event($request);

        ($this->subscriber('en'))->onKernelRequest($event);

        $this->assertSame('en', $request->getLocale());
    }

    public function testFallbackWhenAllSourcesMissing(): void
    {
        $prefs = $this->createMock(DisplayPreferencesService::class);
        $prefs->method('getLanguage')->willReturn('');
        $subscriber = new LocaleSubscriber($prefs);

        $request = Request::create('/');
        $event = $this->event($request);

        $subscriber->onKernelRequest($event);

        $this->assertSame('fr', $request->getLocale());
    }

    public function testUnknownLocaleIsRejected(): void
    {
        $request = Request::create('/?_locale=zz');
        $request->cookies->set(LocaleSubscriber::COOKIE_NAME, 'qq');
        $event = $this->event($request);

        ($this->subscriber('en'))->onKernelRequest($event);

        // Falls through all three bogus sources → the en preference wins.
        $this->assertSame('en', $request->getLocale());
    }

    public function testPrefServiceThrowingFallsBack(): void
    {
        $prefs = $this->createMock(DisplayPreferencesService::class);
        $prefs->method('getLanguage')->willThrowException(new \RuntimeException('db down'));
        $subscriber = new LocaleSubscriber($prefs);

        $request = Request::create('/');
        $event = $this->event($request);

        $subscriber->onKernelRequest($event);

        $this->assertSame('fr', $request->getLocale());
    }
}
