<?php

namespace App\Entity\Media;

use App\Repository\Media\EpisodeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EpisodeRepository::class)]
#[ORM\Table(name: 'media_episode')]
#[ORM\Index(columns: ['series_id', 'season_number', 'episode_number'], name: 'idx_episode_series_se')]
class Episode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Series::class, inversedBy: 'episodes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Series $series;

    #[ORM\Column(nullable: true)]
    private ?int $sonarrEpisodeId = null;

    #[ORM\Column]
    private int $seasonNumber;

    #[ORM\Column]
    private int $episodeNumber;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $overview = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $airDate = null;

    #[ORM\Column(nullable: false, options: ['default' => false])]
    private bool $hasFile = false;

    #[ORM\Column(nullable: false, options: ['default' => false])]
    private bool $monitored = false;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $quality = null;

    public function getId(): ?int { return $this->id; }

    public function getSeries(): Series { return $this->series; }
    public function setSeries(Series $series): static { $this->series = $series; return $this; }

    public function getSonarrEpisodeId(): ?int { return $this->sonarrEpisodeId; }
    public function setSonarrEpisodeId(?int $sonarrEpisodeId): static { $this->sonarrEpisodeId = $sonarrEpisodeId; return $this; }

    public function getSeasonNumber(): int { return $this->seasonNumber; }
    public function setSeasonNumber(int $seasonNumber): static { $this->seasonNumber = $seasonNumber; return $this; }

    public function getEpisodeNumber(): int { return $this->episodeNumber; }
    public function setEpisodeNumber(int $episodeNumber): static { $this->episodeNumber = $episodeNumber; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): static { $this->title = $title; return $this; }

    public function getOverview(): ?string { return $this->overview; }
    public function setOverview(?string $overview): static { $this->overview = $overview; return $this; }

    public function getAirDate(): ?\DateTimeImmutable { return $this->airDate; }
    public function setAirDate(?\DateTimeImmutable $airDate): static { $this->airDate = $airDate; return $this; }

    public function isHasFile(): bool { return $this->hasFile; }
    public function setHasFile(bool $hasFile): static { $this->hasFile = $hasFile; return $this; }

    public function isMonitored(): bool { return $this->monitored; }
    public function setMonitored(bool $monitored): static { $this->monitored = $monitored; return $this; }

    public function getQuality(): ?string { return $this->quality; }
    public function setQuality(?string $quality): static { $this->quality = $quality; return $this; }
}
