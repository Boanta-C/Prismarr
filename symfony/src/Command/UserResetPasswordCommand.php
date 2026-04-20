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
    description: 'Réinitialise le mot de passe d\'un utilisateur (recovery admin).',
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
            ->addArgument('email', InputArgument::REQUIRED, 'Adresse email du compte à réinitialiser')
            ->setHelp(<<<'HELP'
Réinitialise le mot de passe d'un utilisateur existant. À utiliser si
l'admin a perdu ses identifiants.

Exemple :
  docker exec -it prismarr php bin/console app:user:reset-password admin@exemple.com
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = trim((string) $input->getArgument('email'));

        $user = $this->users->findOneBy(['email' => $email]);
        if ($user === null) {
            $io->error(sprintf('Aucun compte trouvé pour "%s".', $email));
            return Command::FAILURE;
        }

        $io->section(sprintf('Réinitialisation du mot de passe — %s', $email));

        $password = $this->askPassword($io, 'Nouveau mot de passe : ');
        if ($password === null) {
            return Command::FAILURE;
        }

        $confirm = $this->askPassword($io, 'Confirmez le mot de passe : ');
        if ($confirm === null) {
            return Command::FAILURE;
        }

        if ($password !== $confirm) {
            $io->error('Les deux mots de passe ne correspondent pas.');
            return Command::FAILURE;
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->flush();

        $io->success(sprintf('Mot de passe réinitialisé pour %s.', $email));
        return Command::SUCCESS;
    }

    private function askPassword(SymfonyStyle $io, string $label): ?string
    {
        $question = new Question($label);
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $value = (string) $io->askQuestion($question);

        if (strlen($value) < self::MIN_LENGTH) {
            $io->error(sprintf('Le mot de passe doit faire au moins %d caractères.', self::MIN_LENGTH));
            return null;
        }

        return $value;
    }
}
