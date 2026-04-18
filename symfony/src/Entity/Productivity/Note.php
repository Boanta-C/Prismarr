<?php

namespace App\Entity\Productivity;

use App\Entity\User;
use App\Repository\Productivity\NoteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteRepository::class)]
#[ORM\Table(name: 'productivity_note')]
class Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(nullable: false, options: ['default' => false])]
    private bool $pinned = false;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $color = null; // couleur hex #rrggbb pour étiquette visuelle

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getContent(): ?string { return $this->content; }
    public function setContent(?string $content): static { $this->content = $content; return $this; }

    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $category): static { $this->category = $category; return $this; }

    public function isPinned(): bool { return $this->pinned; }
    public function setPinned(bool $pinned): static { $this->pinned = $pinned; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): static { $this->color = $color; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
}
