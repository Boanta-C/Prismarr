<?php

namespace App\Tests\MessageHandler;

use App\Entity\Infrastructure\Device;
use App\Entity\Infrastructure\ServiceStatus;
use App\Message\PollDockerMessage;
use App\MessageHandler\PollDockerHandler;
use App\Service\Docker\DockerClient;
use App\Service\WorkerHealthService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;

class PollDockerHandlerTest extends TestCase
{
    private DockerClient&MockObject $docker;
    private EntityManagerInterface&MockObject $em;
    private ManagerRegistry&MockObject $doctrine;
    private LoggerInterface&MockObject $logger;
    private HubInterface&MockObject $hub;
    private WorkerHealthService&MockObject $health;
    private PollDockerHandler $handler;

    protected function setUp(): void
    {
        $this->docker   = $this->createMock(DockerClient::class);
        $this->em       = $this->createMock(EntityManagerInterface::class);
        $this->doctrine = $this->createMock(ManagerRegistry::class);
        $this->logger   = $this->createMock(LoggerInterface::class);
        $this->hub      = $this->createMock(HubInterface::class);
        $this->health   = $this->createMock(WorkerHealthService::class);

        $this->em->method('isOpen')->willReturn(true);
        $this->doctrine->method('getManager')->willReturn($this->em);

        $this->handler = new PollDockerHandler(
            $this->docker,
            $this->doctrine,
            $this->logger,
            $this->hub,
            $this->health,
        );
    }

    // -----------------------------------------------------------------------
    // Résistance aux erreurs
    // -----------------------------------------------------------------------

    public function testHandlerDoesNotCrashWhenDockerThrows(): void
    {
        $this->docker->method('getContainers')
            ->willThrowException(new \RuntimeException('Docker socket unreachable'));

        $this->health->expects($this->once())
            ->method('reportFailure')
            ->with('worker:docker', 'mac-mini', $this->stringContains('Docker socket unreachable'));

        $this->health->expects($this->never())->method('reportSuccess');

        // Ne doit pas lever d'exception
        ($this->handler)(new PollDockerMessage());
    }

    public function testHandlerDoesNotCrashWhenFlushThrows(): void
    {
        $this->setupDockerWithContainers();
        $this->em->method('flush')->willThrowException(new \RuntimeException('MySQL server has gone away'));

        $this->health->expects($this->once())->method('reportFailure')
            ->with('worker:docker', 'mac-mini', $this->stringContains('MySQL server has gone away'));

        ($this->handler)(new PollDockerMessage());
    }

    public function testHandlerReportsSuccessOnNominalRun(): void
    {
        $this->setupDockerWithContainers();
        $this->em->method('flush');

        $this->health->expects($this->once())->method('reportSuccess')->with('worker:docker');
        $this->health->expects($this->never())->method('reportFailure');

        ($this->handler)(new PollDockerMessage());
    }

    // -----------------------------------------------------------------------
    // Reset EntityManager fermé
    // -----------------------------------------------------------------------

    public function testResetsClosedEntityManagerBeforeInvoke(): void
    {
        $closedEm = $this->createMock(EntityManagerInterface::class);
        $closedEm->method('isOpen')->willReturn(false);

        $freshEm = $this->createMock(EntityManagerInterface::class);
        $freshEm->method('isOpen')->willReturn(true);
        $freshEm->method('flush');

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($closedEm, $freshEm);
        $doctrine->expects($this->once())->method('resetManager');

        $this->setupEmForContainers($freshEm);

        $handler = new PollDockerHandler(
            $this->docker,
            $doctrine,
            $this->logger,
            $this->hub,
            $this->health,
        );

        $this->setupDockerWithContainers();
        ($handler)(new PollDockerMessage());
    }

    // -----------------------------------------------------------------------
    // Contenu — statuts containers
    // -----------------------------------------------------------------------

    public function testRunningContainerMapsToStatusUp(): void
    {
        $capturedService = null;

        $this->docker->method('getContainers')->willReturn([
            ['id' => 'abc123', 'name' => 'argos_app', 'state' => 'running', 'created' => time() - 3600],
        ]);
        $this->docker->method('getContainerStats')->willReturn([
            'cpu_percent' => 5.0, 'mem_percent' => 20.0, 'mem_usage' => 100 * 1024 * 1024,
        ]);

        $device = new Device();
        $deviceRepo = $this->createMock(EntityRepository::class);
        $deviceRepo->method('findOneBy')->willReturn($device);

        $serviceRepo = $this->createMock(EntityRepository::class);
        $serviceRepo->method('findOneBy')->willReturn(null);
        $serviceRepo->method('findBy')->willReturn([]);

        $this->em->method('getRepository')->willReturnMap([
            [Device::class, $deviceRepo],
            [ServiceStatus::class, $serviceRepo],
        ]);

        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$capturedService) {
            if ($entity instanceof ServiceStatus) {
                $capturedService = $entity;
            }
        });
        $this->em->method('flush');

        ($this->handler)(new PollDockerMessage());

        $this->assertNotNull($capturedService);
        $this->assertSame('up', $capturedService->getStatus());
    }

    public function testExitedContainerMapsToStatusDown(): void
    {
        $capturedService = null;

        $this->docker->method('getContainers')->willReturn([
            ['id' => 'abc123', 'name' => 'argos_redis', 'state' => 'exited', 'created' => time() - 3600],
        ]);

        $device     = new Device();
        $deviceRepo = $this->createMock(EntityRepository::class);
        $deviceRepo->method('findOneBy')->willReturn($device);

        $serviceRepo = $this->createMock(EntityRepository::class);
        $serviceRepo->method('findOneBy')->willReturn(null);
        $serviceRepo->method('findBy')->willReturn([]);

        $this->em->method('getRepository')->willReturnMap([
            [Device::class, $deviceRepo],
            [ServiceStatus::class, $serviceRepo],
        ]);

        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$capturedService) {
            if ($entity instanceof ServiceStatus) {
                $capturedService = $entity;
            }
        });
        $this->em->method('flush');

        ($this->handler)(new PollDockerMessage());

        $this->assertNotNull($capturedService);
        $this->assertSame('down', $capturedService->getStatus());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function setupDockerWithContainers(): void
    {
        $this->docker->method('getContainers')->willReturn([
            ['id' => 'abc123', 'name' => 'argos_app', 'state' => 'running', 'created' => time() - 3600],
        ]);
        $this->docker->method('getContainerStats')->willReturn([
            'cpu_percent' => 5.0, 'mem_percent' => 20.0, 'mem_usage' => 100 * 1024 * 1024,
        ]);
        $this->setupEmForContainers($this->em);
    }

    private function setupEmForContainers(EntityManagerInterface&MockObject $em): void
    {
        $device     = new Device();
        $deviceRepo = $this->createMock(EntityRepository::class);
        $deviceRepo->method('findOneBy')->willReturn($device);

        $serviceRepo = $this->createMock(EntityRepository::class);
        $serviceRepo->method('findOneBy')->willReturn(null);
        $serviceRepo->method('findBy')->willReturn([]);

        $em->method('getRepository')->willReturnMap([
            [Device::class, $deviceRepo],
            [ServiceStatus::class, $serviceRepo],
        ]);
        $em->method('persist');
    }
}
