<?php

namespace App\Entity\Media;

use App\Repository\Media\DownloadRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DownloadRepository::class)]
#[ORM\Table(name: 'media_download')]
class Download
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 20)]
    private string $mediaType; // movie, series, episode

    #[ORM\Column(length: 20)]
    private string $source; // radarr, sonarr, manual

    #[ORM\Column(length: 20, options: ['default' => 'queued'])]
    private string $status = 'queued'; // queued, downloading, paused, completed, failed, warning

    #[ORM\Column(nullable: true)]
    private ?float $sizeBytes = null;

    #[ORM\Column(nullable: true)]
    private ?float $downloadedBytes = null;

    #[ORM\Column(nullable: true)]
    private ?float $progressPercent = null;

    #[ORM\Column(nullable: true)]
    private ?int $eta = null; // secondes restantes

    #[ORM\Column(nullable: true)]
    private ?float $downloadSpeed = null; // bytes/s

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $downloadClientId = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $quality = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $syncedAt;

    public function __construct()
    {
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getMediaType(): string { return $this->mediaType; }
    public function setMediaType(string $mediaType): static { $this->mediaType = $mediaType; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $source): static { $this->source = $source; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getSizeBytes(): ?float { return $this->sizeBytes; }
    public function setSizeBytes(?float $sizeBytes): static { $this->sizeBytes = $sizeBytes; return $this; }

    public function getDownloadedBytes(): ?float { return $this->downloadedBytes; }
    public function setDownloadedBytes(?float $downloadedBytes): static { $this->downloadedBytes = $downloadedBytes; return $this; }

    public function getProgressPercent(): ?float { return $this->progressPercent; }
    public function setProgressPercent(?float $progressPercent): static { $this->progressPercent = $progressPercent; return $this; }

    public function getEta(): ?int { return $this->eta; }
    public function setEta(?int $eta): static { $this->eta = $eta; return $this; }

    public function getDownloadSpeed(): ?float { return $this->downloadSpeed; }
    public function setDownloadSpeed(?float $downloadSpeed): static { $this->downloadSpeed = $downloadSpeed; return $this; }

    public function getDownloadClientId(): ?string { return $this->downloadClientId; }
    public function setDownloadClientId(?string $downloadClientId): static { $this->downloadClientId = $downloadClientId; return $this; }

    public function getQuality(): ?string { return $this->quality; }
    public function setQuality(?string $quality): static { $this->quality = $quality; return $this; }

    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }

    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $completedAt): static { $this->completedAt = $completedAt; return $this; }

    public function getSyncedAt(): \DateTimeImmutable { return $this->syncedAt; }
    public function setSyncedAt(\DateTimeImmutable $syncedAt): static { $this->syncedAt = $syncedAt; return $this; }
}
