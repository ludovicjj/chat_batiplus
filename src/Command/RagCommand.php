<?php

namespace App\Command;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'rag:test')]
class RagCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Vérifier les connexions
        $appConnection = $this->doctrine->getConnection('app');
        $ragConnection = $this->doctrine->getConnection('postgres');

        $io->text('App DB: ' . $appConnection->getDatabase());
        $io->text('RAG DB: ' . $ragConnection->getDatabase());

        // Vérifier qu'elles sont différentes !
        if ($appConnection->getDatabase() === $ragConnection->getDatabase()) {
            $io->error('DANGER: Même base de données !');
            return Command::FAILURE;
        }

        $io->success('Connexions séparées ✅');
        return Command::SUCCESS;
    }
}