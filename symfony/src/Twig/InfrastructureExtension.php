<?php

namespace App\Twig;

use App\Entity\Infrastructure\Device;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class InfrastructureExtension extends AbstractExtension
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('infrastructure_nav_devices', $this->getNavDevices(...)),
        ];
    }

    /**
     * Retourne la liste des devices surveillés pour la sidebar.
     * Ordonnés par ID (= ordre de découverte) — auto-incrémental.
     *
     * @return Device[]
     */
    public function getNavDevices(): array
    {
        return $this->em->getRepository(Device::class)
            ->findBy(['isMonitored' => true], ['id' => 'ASC']);
    }
}
