<?php

namespace App\Tests\Service;

use App\Entity\Infrastructure\Alert;
use App\Entity\Infrastructure\Device;
use App\Service\WorkerHealthService;
use Doctrine\ORM\Query;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WorkerHealthServiceTest extends TestCase
{
    private ManagerRegistry&MockObject $doctrine;
    private EntityManagerInterface&MockObject $em;
    private LoggerInterface&MockObject $logger;
    private WorkerHealthService $service;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->doctrine = $this->createMock(ManagerRegistry::class);
        $this->logger  = $this->createMock(LoggerInterface::class);

        $this->em->method('isOpen')->willReturn(true);
        $this->doctrine->method('getManager')->willReturn($this->em);

        $this->service = new WorkerHealthService($this->doctrine, $this->logger);
    }

    // -----------------------------------------------------------------------
    // reportFailure
    // -----------------------------------------------------------------------

    public function testReportFailureDoesNotCreateAlertBelowThreshold(): void
    {
        // 2 premiers échecs → pas d'alerte (seuil = 3)
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $this->service->reportFailure('worker:docker', 'mac-mini', 'BDD coupée');
        $this->service->reportFailure('worker:docker', 'mac-mini', 'BDD coupée');
    }

    public function testReportFailureCreatesAlertAtThreshold(): void
    {
        $device = new Device();

        $deviceRepo = $this->createMock(EntityRepository::class);
        $deviceRepo->method('findOneBy')->willReturn($device);

        $this->em->method('getRepository')->willReturn($deviceRepo);
        $this->mockFindActiveAlert(null);

        // Seulement au 3ème échec
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(Alert::class));
        $this->em->expects($this->once())->method('flush');

        $this->service->reportFailure('worker:docker2', 'mac-mini', 'BDD coupée');
        $this->service->reportFailure('worker:docker2', 'mac-mini', 'BDD coupée');
        $this->service->reportFailure('worker:docker2', 'mac-mini', 'BDD coupée');
    }

    public function testReportFailureDoesNotCreateDuplicateAlert(): void
    {
        $existingAlert = new Alert();
        $this->mockFindActiveAlert($existingAlert);

        // 3 fois pour passer le threshold, mais alerte déjà ouverte
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $this->service->reportFailure('worker:docker3', 'mac-mini', 'BDD coupée');
        $this->service->reportFailure('worker:docker3', 'mac-mini', 'BDD coupée');
        $this->service->reportFailure('worker:docker3', 'mac-mini', 'BDD coupée');
    }

    public function testReportFailureSurvivesWhenDbThrows(): void
    {
        $this->em->method('createQueryBuilder')->willThrowException(new \RuntimeException('Connection refused'));

        $this->logger->expects($this->once())->method('error');

        // Atteindre le threshold pour déclencher la tentative DB
        $this->service->reportFailure('worker:docker4', 'mac-mini', 'BDD coupée');
        $this->service->reportFailure('worker:docker4', 'mac-mini', 'BDD coupée');
        $this->service->reportFailure('worker:docker4', 'mac-mini', 'BDD coupée');
    }

    // -----------------------------------------------------------------------
    // reportSuccess
    // -----------------------------------------------------------------------

    public function testReportSuccessResolvesExistingAlert(): void
    {
        $alert = new Alert();
        $this->mockFindActiveAlert($alert);

        $this->em->expects($this->once())->method('flush');

        $this->service->reportSuccess('worker:docker');

        $this->assertNotNull($alert->getResolvedAt());
    }

    public function testReportSuccessResetsFailureCounter(): void
    {
        // 2 échecs, puis succès → compteur remis à 0 → 2 nouveaux échecs ne créent pas d'alerte
        $this->service->reportFailure('worker:counter_test', 'mac-mini', 'err');
        $this->service->reportFailure('worker:counter_test', 'mac-mini', 'err');

        $this->mockFindActiveAlert(null);
        $this->service->reportSuccess('worker:counter_test');

        // Après reset, 2 nouveaux échecs ne doivent pas créer d'alerte
        $this->em->expects($this->never())->method('persist');
        $this->service->reportFailure('worker:counter_test', 'mac-mini', 'err');
        $this->service->reportFailure('worker:counter_test', 'mac-mini', 'err');
    }

    public function testReportSuccessDoesNothingWhenNoActiveAlert(): void
    {
        $this->mockFindActiveAlert(null);

        $this->em->expects($this->never())->method('flush');

        $this->service->reportSuccess('worker:docker');
    }

    public function testReportSuccessSurvivesWhenDbThrows(): void
    {
        $this->em->method('createQueryBuilder')->willThrowException(new \RuntimeException('Connection refused'));

        $this->logger->expects($this->once())->method('warning');

        // Ne doit pas lever d'exception
        $this->service->reportSuccess('worker:docker');
    }

    // -----------------------------------------------------------------------
    // resetEmIfClosed (via ManagerRegistry)
    // -----------------------------------------------------------------------

    public function testResetsEntityManagerWhenClosed(): void
    {
        $closedEm = $this->createMock(EntityManagerInterface::class);
        $closedEm->method('isOpen')->willReturn(false);

        $freshEm = $this->createMock(EntityManagerInterface::class);
        $freshEm->method('isOpen')->willReturn(true);

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($closedEm, $freshEm);
        $doctrine->expects($this->once())->method('resetManager');

        $this->mockFindActiveAlertOn($freshEm, null);
        $freshEm->method('getRepository')->willReturn($this->createMock(EntityRepository::class));

        $service = new WorkerHealthService($doctrine, $this->logger);
        $service->reportSuccess('worker:docker'); // ne doit pas crasher
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function mockFindActiveAlert(?Alert $result): void
    {
        $this->mockFindActiveAlertOn($this->em, $result);
    }

    private function mockFindActiveAlertOn(EntityManagerInterface&MockObject $em, ?Alert $result): void
    {
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($result);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em->method('createQueryBuilder')->willReturn($qb);
    }
}
