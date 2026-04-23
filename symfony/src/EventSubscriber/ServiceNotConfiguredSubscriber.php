<?php

namespace App\EventSubscriber;

use App\Exception\ServiceNotConfiguredException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Turns ServiceNotConfiguredException into a clean error page telling
 * the user to configure the service from the administration area.
 */
class ServiceNotConfiguredSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Environment $twig,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onException', 0],
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        while ($throwable !== null && !$throwable instanceof ServiceNotConfiguredException) {
            $throwable = $throwable->getPrevious();
        }
        if (!$throwable instanceof ServiceNotConfiguredException) {
            return;
        }

        $session = $this->requestStack->getSession();
        if (method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add(
                'warning',
                $this->translator->trans('error.service_not_configured.service_unavailable_warn', ['service' => $throwable->service]),
            );
        }

        $response = new \Symfony\Component\HttpFoundation\Response(
            $this->twig->render('error/service_not_configured.html.twig', [
                'service' => $throwable->service,
                'key'     => $throwable->missingKey,
            ]),
            \Symfony\Component\HttpFoundation\Response::HTTP_SERVICE_UNAVAILABLE,
        );
        $event->setResponse($response);
    }
}
