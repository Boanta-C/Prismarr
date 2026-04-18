<?php

namespace App\Repository\Admin;

use App\Entity\Admin\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /** Dernières entrées pour la vue d'index. */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Entrées pour une table donnée. */
    public function findByTable(string $table, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.tableName = :table')
            ->setParameter('table', $table)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
