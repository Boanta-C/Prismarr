<?php

namespace App\Repository\Infrastructure;

use App\Entity\Infrastructure\Alert;
use App\Entity\Infrastructure\Device;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Alert::class);
    }

    public function findActiveByDeviceAndSource(?Device $device, string $source): ?Alert
    {
        return $this->createQueryBuilder('a')
            ->where('a.device = :device')
            ->andWhere('a.source = :source')
            ->andWhere('a.resolvedAt IS NULL')
            ->setParameter('device', $device)
            ->setParameter('source', $source)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Alert[]
     */
    public function findActiveByDeviceAndSourcePrefix(Device $device, string $prefix): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.device = :device')
            ->andWhere('a.source LIKE :prefix')
            ->andWhere('a.resolvedAt IS NULL')
            ->setParameter('device', $device)
            ->setParameter('prefix', $prefix . '%')
            ->getQuery()
            ->getResult();
    }

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.resolvedAt IS NULL')
            ->andWhere('a.acknowledged = false')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countActiveBySeverity(string $severity): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.resolvedAt IS NULL')
            ->andWhere('a.acknowledged = false')
            ->andWhere('a.severity = :severity')
            ->setParameter('severity', $severity)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
