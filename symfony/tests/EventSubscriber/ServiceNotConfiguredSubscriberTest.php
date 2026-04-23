<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ServiceNotConfiguredSubscriber;
use App\Exception\ServiceNotConfiguredException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;

class ServiceNotConfiguredSubscriberTest extends TestCase
{
    private function event(\Throwable $exception, ?Session $session = null): ExceptionEvent
    {
        $request = Request::create('/whatever');
        if ($session !== null) {
            $request->setSession($session);
        }
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new ExceptionEvent(
            $kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception
        );
    }

    private function subscriber(?Environment $twig = null, ?RequestStack $stack = null): ServiceNotConfiguredSubscriber
    {
        $twig ??= $this->createMock(Environment::class);
        if ($stack === null) {
            $stack = new RequestStack();
            $request = Request::create('/whatever');
            $request->setSession(new Session(new MockArraySessionStorage()));
            $stack->push($request);
        }
        // Translator mock that returns a predictable FR-ish message with the
        // {service} placeholder expanded, so existing assertions still work.
        $translator = $this->createMock(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            function (string $id, array $params = []) {
                $svc = $params['service'] ?? '';

                return $id === 'error.service_not_configured.service_unavailable_warn'
                    ? sprintf('Le service « %s » n\'est pas encore configuré.', $svc)
                    : $id;
            }
        );

        return new ServiceNotConfiguredSubscriber($twig, $stack, $translator);
    }

    public function testLetsThroughUnrelatedException(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->never())->method('render');

        $event = $this->event(new \RuntimeException('other error'));
        ($this->subscriber($twig))->onException($event);

        $this->assertFalse($event->hasResponse());
    }

    public function testHandlesDirectServiceNotConfiguredException(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('error/service_not_configured.html.twig', [
                'service' => 'Radarr',
                'key'     => 'radarr_api_key',
            ])
            ->willReturn('<html>503</html>');

        $event = $this->event(new ServiceNotConfiguredException('Radarr', 'radarr_api_key'));
        ($this->subscriber($twig))->onException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('<html>503</html>', $response->getContent());
    }

    public function testUnwrapsWrappedServiceNotConfiguredException(): void
    {
        // Real-world scenario: a service wraps the exception in a RuntimeException.
        // The subscriber must walk getPrevious() to find it.
        $inner = new ServiceNotConfiguredException('TMDb', 'tmdb_api_key');
        $middle = new \LogicException('middle', 0, $inner);
        $outer = new \RuntimeException('outer', 0, $middle);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('error/service_not_configured.html.twig', [
                'service' => 'TMDb',
                'key'     => 'tmdb_api_key',
            ])
            ->willReturn('<html>503</html>');

        $event = $this->event($outer);
        ($this->subscriber($twig))->onException($event);

        $this->assertInstanceOf(Response::class, $event->getResponse());
    }

    public function testAddsFlashWarningToSession(): void
    {
        $session = new Session(new MockArraySessionStorage());

        $stack = new RequestStack();
        $request = Request::create('/whatever');
        $request->setSession($session);
        $stack->push($request);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $event = $this->event(
            new ServiceNotConfiguredException('Sonarr', 'sonarr_api_key'),
            $session
        );
        ($this->subscriber($twig, $stack))->onException($event);

        $flashes = $session->getFlashBag()->get('warning');
        $this->assertCount(1, $flashes);
        $this->assertStringContainsString('Sonarr', $flashes[0]);
        $this->assertStringContainsString('pas encore configuré', $flashes[0]);
    }
}
