<?php

namespace App\Tests\Command;

use App\Command\UserResetPasswordCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserResetPasswordCommandTest extends TestCase
{
    private function commandTester(
        UserRepository $users,
        ?UserPasswordHasherInterface $hasher = null,
        ?EntityManagerInterface $em = null,
    ): CommandTester {
        $command = new UserResetPasswordCommand(
            $users,
            $hasher ?? $this->createMock(UserPasswordHasherInterface::class),
            $em ?? $this->createMock(EntityManagerInterface::class),
        );

        return new CommandTester($command);
    }

    public function testFailsWhenUserNotFound(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $tester = $this->commandTester($users, null, $em);
        $exitCode = $tester->execute(['email' => 'ghost@example.com']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Aucun compte trouvé', $tester->getDisplay());
    }

    public function testSucceedsWithValidPasswords(): void
    {
        $user = new User();
        $user->setEmail('joshua@example.com');

        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturn($user);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects($this->once())
            ->method('hashPassword')
            ->with($user, 'new-password-123')
            ->willReturn('hashed-value');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $tester = $this->commandTester($users, $hasher, $em);
        $tester->setInputs(['new-password-123', 'new-password-123']);
        $exitCode = $tester->execute(['email' => 'joshua@example.com']);

        $this->assertSame(0, $exitCode);
        $this->assertSame('hashed-value', $user->getPassword());
        $this->assertStringContainsString('réinitialisé', $tester->getDisplay());
    }

    public function testFailsIfPasswordTooShort(): void
    {
        $user = new User();
        $user->setEmail('joshua@example.com');

        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $tester = $this->commandTester($users, null, $em);
        $tester->setInputs(['short']); // only 5 chars
        $exitCode = $tester->execute(['email' => 'joshua@example.com']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('au moins 8 caractères', $tester->getDisplay());
    }

    public function testFailsIfPasswordsDoNotMatch(): void
    {
        $user = new User();
        $user->setEmail('joshua@example.com');

        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $tester = $this->commandTester($users, null, $em);
        $tester->setInputs(['password-123', 'different-456']);
        $exitCode = $tester->execute(['email' => 'joshua@example.com']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('ne correspondent pas', $tester->getDisplay());
    }

    public function testEmailArgumentIsTrimmed(): void
    {
        $user = new User();
        $user->setEmail('joshua@example.com');

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => 'joshua@example.com'])
            ->willReturn($user);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $em = $this->createMock(EntityManagerInterface::class);

        $tester = $this->commandTester($users, $hasher, $em);
        $tester->setInputs(['valid-password', 'valid-password']);
        // leading/trailing whitespace must be stripped
        $exitCode = $tester->execute(['email' => '  joshua@example.com  ']);

        $this->assertSame(0, $exitCode);
    }
}
