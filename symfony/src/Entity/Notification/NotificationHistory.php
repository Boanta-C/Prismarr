<?php

namespace App\Entity\Notification;

use App\Repository\Notification\NotificationHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationHistoryRepository::class)]
#[ORM\Table(name: 'notification_history')]
#[ORM\Index(columns: ['sent_at'], name: 'idx_notif_history_sent_at')]
class NotificationHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NotificationChannel::class, inversedBy: 'history')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private NotificationChannel $channel;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $status = 'pending'; // pending, sent, failed

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $event = null; // alert.critical, download.complete, service.down, etc.

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getChannel(): NotificationChannel { return $this->channel; }
    public function setChannel(NotificationChannel $channel): static { $this->channel = $channel; return $this; }

    public function getSubject(): string { return $this->subject; }
    public function setSubject(string $subject): static { $this->subject = $subject; return $this; }

    public function getBody(): ?string { return $this->body; }
    public function setBody(?string $body): static { $this->body = $body; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getEvent(): ?string { return $this->event; }
    public function setEvent(?string $event): static { $this->event = $event; return $this; }

    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): static { $this->errorMessage = $errorMessage; return $this; }

    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function setSentAt(?\DateTimeImmutable $sentAt): static { $this->sentAt = $sentAt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
