<?php

namespace App\Entity\Infrastructure;

use App\Repository\Infrastructure\AlertRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AlertRepository::class)]
#[ORM\Table(name: 'infrastructure_alert')]
class Alert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Device $device = null;

    #[ORM\Column(length: 20)]
    private string $severity; // critical, warning, info

    #[ORM\Column(length: 255)]
    private string $message;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $source = null; // metric, service, manual

    #[ORM\Column(nullable: false, options: ['default' => false])]
    private bool $acknowledged = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getDevice(): ?Device { return $this->device; }
    public function setDevice(?Device $device): static { $this->device = $device; return $this; }

    public function getSeverity(): string { return $this->severity; }
    public function setSeverity(string $severity): static { $this->severity = $severity; return $this; }

    public function getMessage(): string { return $this->message; }
    public function setMessage(string $message): static { $this->message = $message; return $this; }

    public function getSource(): ?string { return $this->source; }
    public function setSource(?string $source): static { $this->source = $source; return $this; }

    public function isAcknowledged(): bool { return $this->acknowledged; }
    public function setAcknowledged(bool $acknowledged): static { $this->acknowledged = $acknowledged; return $this; }

    public function getAcknowledgedAt(): ?\DateTimeImmutable { return $this->acknowledgedAt; }
    public function setAcknowledgedAt(?\DateTimeImmutable $acknowledgedAt): static { $this->acknowledgedAt = $acknowledgedAt; return $this; }

    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): static { $this->resolvedAt = $resolvedAt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
