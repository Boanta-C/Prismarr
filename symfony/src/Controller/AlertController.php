<?php

namespace App\Controller;

use App\Entity\Infrastructure\Alert;
use App\Repository\Infrastructure\AlertRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class AlertController extends AbstractController
{
    #[Route('/alertes', name: 'app_alertes')]
    public function index(AlertRepository $alertRepo): Response
    {
        $active = $alertRepo->findBy(['resolvedAt' => null], ['createdAt' => 'DESC']);

        $resolved = $alertRepo->createQueryBuilder('a')
            ->where('a.resolvedAt IS NOT NULL')
            ->orderBy('a.resolvedAt', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        return $this->render('alertes/index.html.twig', [
            'active'   => $active,
            'resolved' => $resolved,
        ]);
    }

    #[Route('/alertes/latest', name: 'app_alertes_latest', methods: ['GET'])]
    public function latest(AlertRepository $alertRepo, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $alerts = $alertRepo->findBy(
            ['resolvedAt' => null, 'acknowledged' => false],
            ['createdAt' => 'DESC'],
            5
        );

        return $this->json([
            'count'  => $alertRepo->countActive(),
            'alerts' => array_map(fn(Alert $a) => [
                'id'       => $a->getId(),
                'message'  => $a->getMessage(),
                'severity' => $a->getSeverity(),
                'device'   => $a->getDevice()?->getName(),
                'date'     => $a->getCreatedAt()->format('d/m H:i'),
                'token'    => $csrf->getToken('resolve' . $a->getId())->getValue(),
            ], $alerts),
        ]);
    }

    #[Route('/alertes/{id}/acknowledge', name: 'app_alertes_acknowledge', methods: ['POST'])]
    public function acknowledge(Alert $alert, EntityManagerInterface $em): Response
    {
        $alert->setAcknowledged(true)
              ->setAcknowledgedAt(new \DateTimeImmutable());
        $em->flush();

        $this->addFlash('success', 'Alerte acquittée.');

        return $this->redirectToRoute('app_alertes');
    }

    #[Route('/alertes/{id}/resolve', name: 'app_alertes_resolve', methods: ['POST'])]
    public function resolve(Alert $alert, EntityManagerInterface $em, Request $request): Response
    {
        $alert->setResolvedAt(new \DateTimeImmutable());
        $em->flush();

        if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
            return $this->json(['ok' => true]);
        }

        $this->addFlash('success', 'Alerte résolue manuellement.');

        return $this->redirectToRoute('app_alertes');
    }
}
