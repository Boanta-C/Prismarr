<?php

namespace App\Entity\Media;

use App\Repository\Media\WatchlistItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WatchlistItemRepository::class)]
#[ORM\Table(name: 'media_watchlist')]
#[ORM\UniqueConstraint(name: 'uniq_watchlist_tmdb', columns: ['tmdb_id', 'media_type'])]
class WatchlistItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $tmdbId;

    #[ORM\Column(length: 10)]
    private string $mediaType; // 'movie' | 'tv'

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $posterPath = null;

    #[ORM\Column(nullable: true)]
    private ?float $vote = null;

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    #[ORM\Column]
    private \DateTimeImmutable $addedAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->addedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTmdbId(): int { return $this->tmdbId; }
    public function setTmdbId(int $tmdbId): static { $this->tmdbId = $tmdbId; return $this; }

    public function getMediaType(): string { return $this->mediaType; }
    public function setMediaType(string $mediaType): static { $this->mediaType = $mediaType; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getPosterPath(): ?string { return $this->posterPath; }
    public function setPosterPath(?string $posterPath): static { $this->posterPath = $posterPath; return $this; }

    public function getVote(): ?float { return $this->vote; }
    public function setVote(?float $vote): static { $this->vote = $vote; return $this; }

    public function getYear(): ?int { return $this->year; }
    public function setYear(?int $year): static { $this->year = $year; return $this; }

    public function getAddedAt(): \DateTimeImmutable { return $this->addedAt; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }
}
