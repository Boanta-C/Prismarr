<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:reset-password',
    description: 'Reset a user password (admin recovery).',
)]
class UserResetPasswordCommand extends Command
{
    private const MIN_LENGTH = 8;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address of the account to reset')
            ->setHelp(<<<'HELP'
Reset the password of an existing user. Useful when the admin has
lost their credentials.

Example:
  docker exec -it prismarr php bin/console app:user:reset-password admin@example.com
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = trim((string) $input->getArgument('email'));

        $user = $this->users->findOneBy(['email' => $email]);
        if ($user === null) {
            $io->error(sprintf('No account found for "%s".', $email));
            return Command::FAILURE;
        }

        $io->section(sprintf('Password reset — %s', $email));

        $password = $this->askPassword($io, 'New password: ');
        if ($password === null) {
            return Command::FAILURE;
        }

        $confirm = $this->askPassword($io, 'Confirm password: ');
        if ($confirm === null) {
            return Command::FAILURE;
        }

        if ($password !== $confirm) {
            $io->error('The two passwords do not match.');
            return Command::FAILURE;
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->flush();

        $io->success(sprintf('Password reset for %s.', $email));
        return Command::SUCCESS;
    }

    private function askPassword(SymfonyStyle $io, string $label): ?string
    {
        $question = new Question($label);
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $value = (string) $io->askQuestion($question);

        if (strlen($value) < self::MIN_LENGTH) {
            $io->error(sprintf('Password must be at least %d characters long.', self::MIN_LENGTH));
            return null;
        }

        return $value;
    }
}
