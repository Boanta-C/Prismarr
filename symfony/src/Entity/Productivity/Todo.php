<?php

namespace App\Entity\Productivity;

use App\Entity\User;
use App\Repository\Productivity\TodoRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TodoRepository::class)]
#[ORM\Table(name: 'productivity_todo')]
class Todo
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
    private ?string $description = null;

    #[ORM\Column(length: 20, options: ['default' => 'todo'])]
    private string $status = 'todo'; // todo, in_progress, done, cancelled

    #[ORM\Column(length: 10, options: ['default' => 'medium'])]
    private string $priority = 'medium'; // low, medium, high, urgent

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

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

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getPriority(): string { return $this->priority; }
    public function setPriority(string $priority): static { $this->priority = $priority; return $this; }

    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $category): static { $this->category = $category; return $this; }

    public function getDueAt(): ?\DateTimeImmutable { return $this->dueAt; }
    public function setDueAt(?\DateTimeImmutable $dueAt): static { $this->dueAt = $dueAt; return $this; }

    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $completedAt): static { $this->completedAt = $completedAt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
}
