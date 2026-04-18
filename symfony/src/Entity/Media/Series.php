<?php

namespace App\Entity\Media;

use App\Repository\Media\SeriesRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SeriesRepository::class)]
#[ORM\Table(name: 'media_series')]
class Series
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?int $sonarrId = null;

    #[ORM\Column(nullable: true)]
    private ?int $tvdbId = null;

    #[ORM\Column(nullable: true)]
    private ?int $tmdbId = null;

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

    #[ORM\Column(length: 20, options: ['default' => 'continuing'])]
    private string $seriesType = 'continuing'; // continuing, ended, upcoming

    #[ORM\Column(length: 20, options: ['default' => 'unknown'])]
    private string $status = 'unknown'; // monitored, unmonitored, ended

    #[ORM\Column(nullable: true)]
    private ?int $seasonCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $episodeCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $episodeFileCount = null;

    #[ORM\Column(nullable: true)]
    private ?float $sizeOnDisk = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $addedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $syncedAt;

    /** @var Collection<int, Episode> */
    #[ORM\OneToMany(targetEntity: Episode::class, mappedBy: 'series', orphanRemoval: true)]
    private Collection $episodes;

    public function __construct()
    {
        $this->syncedAt = new \DateTimeImmutable();
        $this->episodes = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getSonarrId(): ?int { return $this->sonarrId; }
    public function setSonarrId(?int $sonarrId): static { $this->sonarrId = $sonarrId; return $this; }

    public function getTvdbId(): ?int { return $this->tvdbId; }
    public function setTvdbId(?int $tvdbId): static { $this->tvdbId = $tvdbId; return $this; }

    public function getTmdbId(): ?int { return $this->tmdbId; }
    public function setTmdbId(?int $tmdbId): static { $this->tmdbId = $tmdbId; return $this; }

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

    public function getSeriesType(): string { return $this->seriesType; }
    public function setSeriesType(string $seriesType): static { $this->seriesType = $seriesType; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getSeasonCount(): ?int { return $this->seasonCount; }
    public function setSeasonCount(?int $seasonCount): static { $this->seasonCount = $seasonCount; return $this; }

    public function getEpisodeCount(): ?int { return $this->episodeCount; }
    public function setEpisodeCount(?int $episodeCount): static { $this->episodeCount = $episodeCount; return $this; }

    public function getEpisodeFileCount(): ?int { return $this->episodeFileCount; }
    public function setEpisodeFileCount(?int $episodeFileCount): static { $this->episodeFileCount = $episodeFileCount; return $this; }

    public function getSizeOnDisk(): ?float { return $this->sizeOnDisk; }
    public function setSizeOnDisk(?float $sizeOnDisk): static { $this->sizeOnDisk = $sizeOnDisk; return $this; }

    public function getAddedAt(): ?\DateTimeImmutable { return $this->addedAt; }
    public function setAddedAt(?\DateTimeImmutable $addedAt): static { $this->addedAt = $addedAt; return $this; }

    public function getSyncedAt(): \DateTimeImmutable { return $this->syncedAt; }
    public function setSyncedAt(\DateTimeImmutable $syncedAt): static { $this->syncedAt = $syncedAt; return $this; }

    public function getEpisodes(): Collection { return $this->episodes; }
}
