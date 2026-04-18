<?php

namespace App\Repository;

use App\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Setting>
 */
class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    public function get(string $name): ?string
    {
        $setting = $this->find($name);
        return $setting?->getValue();
    }

    public function set(string $name, ?string $value): void
    {
        $em = $this->getEntityManager();
        $setting = $this->find($name);
        if ($setting === null) {
            $setting = new Setting($name, $value);
            $em->persist($setting);
        } else {
            $setting->setValue($value);
        }
        $em->flush();
    }

    /**
     * @param array<string, ?string> $values
     */
    public function setMany(array $values): void
    {
        $em = $this->getEntityManager();
        foreach ($values as $name => $value) {
            $setting = $this->find($name);
            if ($setting === null) {
                $em->persist(new Setting($name, $value));
            } else {
                $setting->setValue($value);
            }
        }
        $em->flush();
    }

    /**
     * @return array<string, string>
     */
    public function getAll(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.name, s.value')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            if ($row['value'] !== null) {
                $map[$row['name']] = $row['value'];
            }
        }
        return $map;
    }
}
