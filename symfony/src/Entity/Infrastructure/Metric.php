<?php

namespace App\Entity\Infrastructure;

use App\Repository\Infrastructure\MetricRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MetricRepository::class)]
#[ORM\Table(name: 'infrastructure_metric')]
#[ORM\Index(columns: ['device_id', 'name', 'recorded_at'], name: 'idx_metric_device_name_time')]
class Metric
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Device::class, inversedBy: 'metrics')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\Column(length: 50)]
    private string $name; // cpu_percent, ram_percent, disk_percent, network_in, network_out, temperature

    #[ORM\Column]
    private float $value;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $unit = null; // %, MB, MB/s, °C

    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    public function __construct()
    {
        $this->recordedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getDevice(): Device { return $this->device; }
    public function setDevice(Device $device): static { $this->device = $device; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getValue(): float { return $this->value; }
    public function setValue(float $value): static { $this->value = $value; return $this; }

    public function getUnit(): ?string { return $this->unit; }
    public function setUnit(?string $unit): static { $this->unit = $unit; return $this; }

    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }
    public function setRecordedAt(\DateTimeImmutable $recordedAt): static { $this->recordedAt = $recordedAt; return $this; }
}
