<?php

namespace App\Command;

use App\Message\PollDockerMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:poll-docker',
    description: 'Déclenche manuellement le polling Docker (test)',
)]
class PollDockerCommand extends Command
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Dispatching PollDockerMessage...');
        $this->bus->dispatch(new PollDockerMessage());
        $output->writeln('<info>Message dispatché dans la queue async.</info>');

        return Command::SUCCESS;
    }
}
