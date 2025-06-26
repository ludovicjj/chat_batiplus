<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Chatbot\ChatbotService;
use App\Service\SQL\SqlSchemaService;
use App\Service\SQL\SqlSecurityService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'chatbot:test',
    description: 'Test the chatbot system components'
)]
class ChatbotTestCommand extends Command
{
    public function __construct(
        private readonly ChatbotService $chatbotService,
        private readonly SqlSchemaService $schemaService,
        private readonly SqlSecurityService $sqlSecurity
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('question', null, InputOption::VALUE_OPTIONAL, 'Test question to ask the chatbot')
            ->addOption('schema', 's', InputOption::VALUE_NONE, 'Display database schema')
            ->addOption('security', null, InputOption::VALUE_NONE, 'Test SQL security validation')
            ->setHelp(
                'This command tests various components of the chatbot system.' . PHP_EOL .
                'Examples:' . PHP_EOL .
                '  php bin/console chatbot:test --schema' . PHP_EOL .
                '  php bin/console chatbot:test --security' . PHP_EOL .
                '  php bin/console chatbot:test --question "Combien de clients avons-nous ?"'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('ChatBot BatiPlus - Test des composants');

        // Test system status
        $io->section('Statut du système');
        try {
            $status = $this->chatbotService->getSystemStatus();
            $io->definitionList(
                ['Base de données' => $status['database']],
                ['Tables autorisées' => implode(', ', $status['allowed_tables'])],
                ['Timestamp' => $status['timestamp']->format('Y-m-d H:i:s')]
            );
        } catch (\Exception $e) {
            $io->error('Erreur lors du test du système: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Display schema if requested
        if ($input->getOption('schema')) {
            $io->section('Schéma de la base de données');
            try {
                $schema = $this->schemaService->getTablesStructure();

                if (empty($schema)) {
                    $io->warning('Aucune table trouvée dans le schéma');
                } else {
                    foreach ($schema as $tableName => $columns) {
                        $io->writeln("<info>Table: {$tableName}</info>");
                        $io->listing($columns);
                    }
                }
            } catch (\Exception $e) {
                $io->error('Erreur lors de la récupération du schéma: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        // Test SQL security if requested
        if ($input->getOption('security')) {
            $io->section('Test de la sécurité SQL');
            $this->testSqlSecurity($io);
        }

        // Test with a question if provided
        $question = $input->getOption('question');
        if ($question) {
            $io->section('Test avec question');
            $io->writeln("Question: <comment>{$question}</comment>");

            try {
                $result = $this->chatbotService->processQuestion($question);

                if ($result['success']) {
                    $io->success('Réponse générée avec succès');
                    $io->writeln('<info>Réponse:</info>');
                    $io->writeln($result['response']);

                    if (isset($result['metadata'])) {
                        $io->writeln('');
                        $io->writeln('<comment>Métadonnées:</comment>');
                        $io->definitionList(
                            ['Requête SQL' => $result['metadata']['sql_query']],
                            ['Temps d\'exécution' => $result['metadata']['execution_time'] . 's'],
                            ['Nombre de résultats' => $result['metadata']['result_count']]
                        );
                    }
                } else {
                    $io->error('Erreur lors du traitement de la question');
                    $io->writeln('<error>Erreur:</error> ' . $result['error']);
                    $io->writeln('<comment>Type d\'erreur:</comment> ' . ($result['error_type'] ?? 'unknown'));
                }
            } catch (\Exception $e) {
                $io->error('Exception lors du test: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        $io->success('Tests terminés');
        return Command::SUCCESS;
    }

    private function testSqlSecurity(SymfonyStyle $io): void
    {
        $testQueries = [
            // Safe queries
            'SELECT * FROM collaborator;' => true,
            'SELECT COUNT(*) FROM report;' => true,
            'SELECT id, name FROM agency WHERE is_enabled = 1;' => true,

            // Unsafe queries
            'DROP TABLE collaborator;' => false,
            'DELETE FROM report;' => false,
            'INSERT INTO agency (name) VALUES ("test");' => false,
            'UPDATE client_case SET name = "test";' => false,
            'SELECT * FROM information_schema.tables;' => false,
            'SELECT * FROM unauthorized_table;' => false,
        ];

        foreach ($testQueries as $query => $shouldPass) {
            try {
                $this->sqlSecurity->validateQuery($query);
                $result = $shouldPass ? '✓ PASS' : '✗ FAIL (should have been blocked)';
                $color = $shouldPass ? 'green' : 'red';
            } catch (\Exception $e) {
                $result = $shouldPass ? '✗ FAIL (should have passed)' : '✓ PASS (correctly blocked)';
                $color = $shouldPass ? 'red' : 'green';
            }

            $io->writeln(sprintf(
                '<%s>%s</%s> %s',
                $color,
                $result,
                $color,
                $query
            ));
        }
    }
}
