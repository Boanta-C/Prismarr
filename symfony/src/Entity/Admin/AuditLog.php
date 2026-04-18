<?php

namespace App\Entity\Admin;

use App\Repository\Admin\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'admin_audit_log')]
#[ORM\Index(columns: ['table_name'], name: 'idx_audit_table')]
#[ORM\Index(columns: ['created_at'], name: 'idx_audit_date')]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    /** Email de l'admin ayant effectué l'action. */
    #[ORM\Column(length: 255)]
    private string $userEmail;

    /** Type d'action : select | insert | update | delete | query */
    #[ORM\Column(length: 20)]
    private string $action;

    /** Table cible (null pour les requêtes libres multi-tables). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $tableName = null;

    /** Identifiant de la ligne modifiée (valeur de la PK). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $rowIdentifier = null;

    /** État de la ligne AVANT modification (null pour INSERT). */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $oldValues = null;

    /** État de la ligne APRÈS modification (null pour DELETE). */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $newValues = null;

    /** IP du client. */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    /** Requête SQL exécutée (pour le query runner). */
    #[ORM\Column(name: 'sql_query', type: Types::TEXT, nullable: true)]
    private ?string $sql = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }

    public function getUserEmail(): string { return $this->userEmail; }
    public function setUserEmail(string $v): static { $this->userEmail = $v; return $this; }

    public function getAction(): string { return $this->action; }
    public function setAction(string $v): static { $this->action = $v; return $this; }

    public function getTableName(): ?string { return $this->tableName; }
    public function setTableName(?string $v): static { $this->tableName = $v; return $this; }

    public function getRowIdentifier(): ?string { return $this->rowIdentifier; }
    public function setRowIdentifier(?string $v): static { $this->rowIdentifier = $v; return $this; }

    public function getOldValues(): ?array { return $this->oldValues; }
    public function setOldValues(?array $v): static { $this->oldValues = $v; return $this; }

    public function getNewValues(): ?array { return $this->newValues; }
    public function setNewValues(?array $v): static { $this->newValues = $v; return $this; }

    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $v): static { $this->ipAddress = $v; return $this; }

    public function getSql(): ?string { return $this->sql; }
    public function setSql(?string $v): static { $this->sql = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
