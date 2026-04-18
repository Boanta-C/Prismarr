<?php

namespace App\Entity\Infrastructure;

use App\Repository\Infrastructure\DeviceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\Table(name: 'infrastructure_device')]
class Device
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 50)]
    private string $type; // server, nas, switch, router, vm, container

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $hostname = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $os = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isMonitored = true;

    #[ORM\Column(length: 20, options: ['default' => 'unknown'])]
    private string $status = 'unknown'; // online, offline, warning, unknown

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Metric> */
    #[ORM\OneToMany(targetEntity: Metric::class, mappedBy: 'device', orphanRemoval: true)]
    private Collection $metrics;

    /** @var Collection<int, ServiceStatus> */
    #[ORM\OneToMany(targetEntity: ServiceStatus::class, mappedBy: 'device', orphanRemoval: true)]
    private Collection $services;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->metrics = new ArrayCollection();
        $this->services = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ipAddress): static { $this->ipAddress = $ipAddress; return $this; }

    public function getHostname(): ?string { return $this->hostname; }
    public function setHostname(?string $hostname): static { $this->hostname = $hostname; return $this; }

    public function getOs(): ?string { return $this->os; }
    public function setOs(?string $os): static { $this->os = $os; return $this; }

    public function isMonitored(): ?bool { return $this->isMonitored; }
    public function setIsMonitored(?bool $isMonitored): static { $this->isMonitored = $isMonitored; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getLastSeenAt(): ?\DateTimeImmutable { return $this->lastSeenAt; }
    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): static { $this->lastSeenAt = $lastSeenAt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getMetrics(): Collection { return $this->metrics; }
    public function getServices(): Collection { return $this->services; }
}
