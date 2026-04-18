<?php

namespace App\Entity\Notification;

use App\Repository\Notification\NotificationChannelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationChannelRepository::class)]
#[ORM\Table(name: 'notification_channel')]
class NotificationChannel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 30)]
    private string $type; // email, discord, telegram, slack, webhook, pushover

    #[ORM\Column(type: 'json')]
    private array $config = []; // stockage souple : webhook_url, token, chat_id, etc.

    #[ORM\Column(nullable: false, options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $triggers = null; // events qui déclenchent ce canal : alert, download_complete, etc.

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, NotificationHistory> */
    #[ORM\OneToMany(targetEntity: NotificationHistory::class, mappedBy: 'channel', orphanRemoval: true)]
    private Collection $history;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->history = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getConfig(): array { return $this->config; }
    public function setConfig(array $config): static { $this->config = $config; return $this; }

    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): static { $this->enabled = $enabled; return $this; }

    public function getTriggers(): ?array { return $this->triggers; }
    public function setTriggers(?array $triggers): static { $this->triggers = $triggers; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getHistory(): Collection { return $this->history; }
}
