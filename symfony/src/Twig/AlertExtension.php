<?php

namespace App\Twig;

use App\Repository\Infrastructure\AlertRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AlertExtension extends AbstractExtension
{
    public function __construct(private readonly AlertRepository $alertRepo) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('alert_active_count', $this->getActiveCount(...)),
            new TwigFunction('alert_latest',       $this->getLatest(...)),
        ];
    }

    public function getActiveCount(): int
    {
        return $this->alertRepo->countActive();
    }

    public function getLatest(): array
    {
        return $this->alertRepo->findBy(
            ['resolvedAt' => null, 'acknowledged' => false],
            ['createdAt' => 'DESC'],
            5
        );
    }
}
