<?php

namespace App\Entity\Productivity;

use App\Entity\User;
use App\Repository\Productivity\CalendarEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CalendarEventRepository::class)]
#[ORM\Table(name: 'productivity_calendar_event')]
class CalendarEvent
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

    #[ORM\Column(length: 20, options: ['default' => 'personal'])]
    private string $type = 'personal'; // personal, media, infrastructure, reminder

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $color = null; // hex couleur de l'événement dans le calendrier

    #[ORM\Column]
    private \DateTimeImmutable $startAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endAt = null;

    #[ORM\Column(nullable: false, options: ['default' => false])]
    private bool $allDay = false;

    #[ORM\Column(nullable: true)]
    private ?int $externalId = null; // ID chez Radarr/Sonarr si événement auto-importé

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $externalSource = null; // radarr, sonarr, jellyseerr

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): static { $this->color = $color; return $this; }

    public function getStartAt(): \DateTimeImmutable { return $this->startAt; }
    public function setStartAt(\DateTimeImmutable $startAt): static { $this->startAt = $startAt; return $this; }

    public function getEndAt(): ?\DateTimeImmutable { return $this->endAt; }
    public function setEndAt(?\DateTimeImmutable $endAt): static { $this->endAt = $endAt; return $this; }

    public function isAllDay(): bool { return $this->allDay; }
    public function setAllDay(bool $allDay): static { $this->allDay = $allDay; return $this; }

    public function getExternalId(): ?int { return $this->externalId; }
    public function setExternalId(?int $externalId): static { $this->externalId = $externalId; return $this; }

    public function getExternalSource(): ?string { return $this->externalSource; }
    public function setExternalSource(?string $externalSource): static { $this->externalSource = $externalSource; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
