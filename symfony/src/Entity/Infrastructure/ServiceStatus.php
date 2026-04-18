<?php

namespace App\Entity\Infrastructure;

use App\Repository\Infrastructure\ServiceStatusRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServiceStatusRepository::class)]
#[ORM\Table(name: 'infrastructure_service_status')]
class ServiceStatus
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Device::class, inversedBy: 'services')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\Column(length: 100)]
    private string $name; // radarr, sonarr, jellyfin, portainer...

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 20, options: ['default' => 'unknown'])]
    private string $status = 'unknown'; // up, down, degraded, error, unknown

    #[ORM\Column(nullable: true)]
    private ?int $responseTimeMs = null;

    #[ORM\Column(nullable: true)]
    private ?int $httpCode = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    /** Contenu libre — ex : rapport integrity_check SQLite, logs d'erreur. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $details = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getDevice(): Device { return $this->device; }
    public function setDevice(Device $device): static { $this->device = $device; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $url): static { $this->url = $url; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getResponseTimeMs(): ?int { return $this->responseTimeMs; }
    public function setResponseTimeMs(?int $responseTimeMs): static { $this->responseTimeMs = $responseTimeMs; return $this; }

    public function getHttpCode(): ?int { return $this->httpCode; }
    public function setHttpCode(?int $httpCode): static { $this->httpCode = $httpCode; return $this; }

    public function getCheckedAt(): ?\DateTimeImmutable { return $this->checkedAt; }
    public function setCheckedAt(?\DateTimeImmutable $checkedAt): static { $this->checkedAt = $checkedAt; return $this; }

    public function getDetails(): ?string { return $this->details; }
    public function setDetails(?string $details): static { $this->details = $details; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
