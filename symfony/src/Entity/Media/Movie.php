<?php

namespace App\Entity\Media;

use App\Repository\Media\MovieRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MovieRepository::class)]
#[ORM\Table(name: 'media_movie')]
class Movie
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?int $radarrId = null;

    #[ORM\Column(nullable: true)]
    private ?int $tmdbId = null;

    #[ORM\Column(nullable: true)]
    private ?int $imdbId = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalTitle = null;

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $overview = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $posterPath = null;

    #[ORM\Column(length: 20, options: ['default' => 'unknown'])]
    private string $status = 'unknown'; // downloaded, monitored, missing, announced

    #[ORM\Column(nullable: true)]
    private ?bool $hasFile = false;

    #[ORM\Column(nullable: true)]
    private ?float $sizeOnDisk = null; // octets

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $quality = null; // 2160p, 1080p, 720p, SD

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $addedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $syncedAt;

    public function __construct()
    {
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getRadarrId(): ?int { return $this->radarrId; }
    public function setRadarrId(?int $radarrId): static { $this->radarrId = $radarrId; return $this; }

    public function getTmdbId(): ?int { return $this->tmdbId; }
    public function setTmdbId(?int $tmdbId): static { $this->tmdbId = $tmdbId; return $this; }

    public function getImdbId(): ?int { return $this->imdbId; }
    public function setImdbId(?int $imdbId): static { $this->imdbId = $imdbId; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getOriginalTitle(): ?string { return $this->originalTitle; }
    public function setOriginalTitle(?string $originalTitle): static { $this->originalTitle = $originalTitle; return $this; }

    public function getYear(): ?int { return $this->year; }
    public function setYear(?int $year): static { $this->year = $year; return $this; }

    public function getOverview(): ?string { return $this->overview; }
    public function setOverview(?string $overview): static { $this->overview = $overview; return $this; }

    public function getPosterPath(): ?string { return $this->posterPath; }
    public function setPosterPath(?string $posterPath): static { $this->posterPath = $posterPath; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function isHasFile(): ?bool { return $this->hasFile; }
    public function setHasFile(?bool $hasFile): static { $this->hasFile = $hasFile; return $this; }

    public function getSizeOnDisk(): ?float { return $this->sizeOnDisk; }
    public function setSizeOnDisk(?float $sizeOnDisk): static { $this->sizeOnDisk = $sizeOnDisk; return $this; }

    public function getQuality(): ?string { return $this->quality; }
    public function setQuality(?string $quality): static { $this->quality = $quality; return $this; }

    public function getAddedAt(): ?\DateTimeImmutable { return $this->addedAt; }
    public function setAddedAt(?\DateTimeImmutable $addedAt): static { $this->addedAt = $addedAt; return $this; }

    public function getSyncedAt(): \DateTimeImmutable { return $this->syncedAt; }
    public function setSyncedAt(\DateTimeImmutable $syncedAt): static { $this->syncedAt = $syncedAt; return $this; }
}
