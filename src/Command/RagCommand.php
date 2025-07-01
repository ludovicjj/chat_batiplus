<?php

namespace App\Command;

use App\Service\Rag\EmbeddingService;
use App\Service\Rag\RagLoader;
use App\Service\Rag\RagService;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'rag:test')]
class RagCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly RagService $ragService,
        private readonly EmbeddingService $embeddingService,
        private readonly RagLoader $ragLoader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('action', null, InputOption::VALUE_REQUIRED, 'action to execute')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Reset table before adding examples');
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getOption('action');
        $reset = $input->getOption('reset');

        return match ($action) {
            'connection' => $this->checkConnection($io),
            'add' => $this->addExample($io, $reset),
            'health' => $this->checkHealth($io),
            'similarity' => $this->checkSimilarity($io),
            'stats' => $this->stats($io),
            default => throw new Exception('Invalid action')
        };
    }

    private function checkConnection(SymfonyStyle $io): int
    {
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

    private function checkHealth(SymfonyStyle $io): int
    {
        try {
            $isHealthy = $this->embeddingService->isHealthy();

            if (!$isHealthy) {
                $io->error('Embedding service health check failed');
                return Command::FAILURE;
            }
            $io->success('Embedding service health check success');
            return Command::SUCCESS;

        } catch (Throwable $e) {
            $io->error('Error : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function addExample(SymfonyStyle $io, bool $reset = false): int
    {
        if ($reset) {
            if (!$this->resetTable($io)) {
                return Command::FAILURE;
            }
        }

        $examples = $this->ragLoader->loadAllExamples();

        try {
            foreach ($examples as $index => $example) {
                $rag = $this->ragService->addExample(
                    $example['question'],
                    $example['query'],
                    $example['intent'],
                    $example['metadata'],
                    $example['tags']
                );

                $io->text("✅ Exemple " . ($index + 1) . " ajouté (ID: {$rag->getId()})");
            }

            $io->success('✅ Tous les exemples initiaux ajoutés');
            return Command::SUCCESS;

        } catch (Exception $e) {
            $io->error('❌ Erreur ajout exemples: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function stats(SymfonyStyle $io): int
    {
        try {
            $stats = $this->ragLoader->getStats();
            $io->title('Dataset RAG');

            // Global info
            $io->section('Informations générales');
            $io->horizontalTable(
                ['Métrique', 'Valeur'],
                [
                    ['Version', $stats['version']],
                    ['Total exemples', $stats['total_examples']],
                    ['Dernière mise à jour', date('d/m/Y H:i')]
                ]
            );

            // Display by Operation
            $io->section('Repartition par opération');
            $operationRows = [];
            $totalOp = array_sum($stats['by_operation']);

            foreach ($stats['by_operation'] as $operation => $count) {
                $percentage = round(($count / $totalOp) * 100, 1);
                $bar = str_repeat('█', (int)($percentage / 5)); // Barre visuelle

                $operationRows[] = [
                    ucfirst($operation),
                    $count,
                    "{$percentage}%",
                    $bar
                ];
            }

            $io->table(
                ['Opération', 'Exemples', 'Pourcentage', 'Répartition'],
                $operationRows
            );

            // Répartition par complexité
            $io->section('⚡ Répartition par complexité');
            $complexityRows = [];
            $totalComplexity = array_sum($stats['by_complexity']);

            // Ordre logique de complexité
            $complexityOrder = ['simple', 'medium', 'complex'];
            $complexityColors = [
                'simple' => '🟢',
                'medium' => '🟡',
                'complex' => '🔴'
            ];

            foreach ($complexityOrder as $complexity) {
                if (isset($stats['by_complexity'][$complexity])) {
                    $count = $stats['by_complexity'][$complexity];
                    $percentage = round(($count / $totalComplexity) * 100, 1);
                    $bar = str_repeat('█', (int)($percentage / 5));
                    $icon = $complexityColors[$complexity] ?? '⚪';

                    $complexityRows[] = [
                        $icon . ' ' . ucfirst($complexity),
                        $count,
                        "{$percentage}%",
                        $bar
                    ];
                }
            }

            $io->table(
                ['Complexité', 'Exemples', 'Pourcentage', 'Répartition'],
                $complexityRows
            );

            // Analyse et recommandations
            $io->section('💡 Analyse et recommandations');

            $recommendations = [];

            // Vérifier l'équilibre opérations
            $countOp = $stats['by_operation']['count'] ?? 0;
            $searchOp = $stats['by_operation']['search'] ?? 0;

            if ($countOp > $searchOp * 2) {
                $recommendations[] = "⚠️  Trop d'exemples de comptage vs recherche (ratio: {$countOp}:{$searchOp})";
            } elseif ($searchOp > $countOp * 2) {
                $recommendations[] = "⚠️  Trop d'exemples de recherche vs comptage (ratio: {$searchOp}:{$countOp})";
            } else {
                $recommendations[] = "✅ Bon équilibre entre comptage et recherche";
            }

            // Vérifier la diversité des entités
            $entityCount = count($stats['by_entity']);
            if ($entityCount < 3) {
                $recommendations[] = "⚠️  Peu de types d'entités ({$entityCount}). Considérez ajouter rapports, clients, etc.";
            } else {
                $recommendations[] = "✅ Bonne diversité d'entités ({$entityCount} types)";
            }

            // Vérifier la complexité
            $simpleCount = $stats['by_complexity']['simple'] ?? 0;
            $complexCount = $stats['by_complexity']['complex'] ?? 0;

            if ($simpleCount > 0 && $complexCount === 0) {
                $recommendations[] = "💡 Considérez ajouter des exemples complexes (nested queries, etc.)";
            } elseif ($complexCount > $simpleCount) {
                $recommendations[] = "⚠️  Beaucoup d'exemples complexes. Assurez-vous d'avoir assez d'exemples simples";
            }

            // Total d'exemples
            if ($stats['total_examples'] < 20) {
                $recommendations[] = "📈 Dataset encore petit ({$stats['total_examples']} exemples). Objectif: 30-50 exemples";
            } elseif ($stats['total_examples'] > 100) {
                $recommendations[] = "📊 Dataset volumineux ({$stats['total_examples']} exemples). Surveillez les performances";
            } else {
                $recommendations[] = "✅ Taille de dataset optimale ({$stats['total_examples']} exemples)";
            }

            foreach ($recommendations as $recommendation) {
                $io->text($recommendation);
            }

            return Command::SUCCESS;
        } catch (Exception $e) {
            $io->error('Error stats: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function checkSimilarity(SymfonyStyle $io): int
    {
        $io->section('Check similarity');
        $tableRows = [];

        $testQuestions = [
            // COMPTAGE - devrait matcher "Combien d'affaires au total ?"
            "Nombre total de dossiers ?",
            "Combien de cases au total ?",

            // RECHERCHE PAR ID - devrait matcher "Affaire avec l'ID 869"
            "Affaire ID 123",
            "Dossier avec l'identifiant 456",

            // RECHERCHE PAR REFERENCE - devrait matcher les exemples de référence
            "Affaire référence ABC123",
            "Dossier 94P0999888",

            // RECHERCHE PAR MANAGER - devrait matcher "Affaires pour le manager William"
            "Dossiers manager Pierre",
            "Affaires gérées par Jean DUPONT",

            // RECHERCHE PAR CLIENT - devrait matcher "Affaires pour le client APHP"
            "Dossiers client Microsoft",
            "Affaires pour SNCF",

            // QUESTIONS DIFFERENTES - ne devrait rien matcher ou faible score
            "Créer une nouvelle affaire",
            "Supprimer le rapport 123"
        ];

        try {
            foreach ($testQuestions as $question) {
                $results = $this->ragService->findSimilarExamples(
                    question: $question,
                    similarityThreshold: 0.65  // Seuil bas pour voir plus de résultats
                );

                if (empty($results)) {
                    $tableRows[] = [
                        '-',
                        "<fg=red>No match</fg=red>",
                        '-',
                        $this->truncate($question, 35),
                        '<fg=gray>-</fg=gray>',
                        '<fg=gray>-</fg=gray>',
                        '<fg=gray>-</fg=gray>',
                    ];
                    continue;
                }

                foreach ($results as $result) {
                    $similarity = number_format(($result->getSimilarityScore() ?? 0) * 100, 1);

                    $scoreDisplay = match(true) {
                        $similarity >= 80 => "<fg=green>{$similarity}%</>",
                        $similarity >= 70 => "<fg=yellow>{$similarity}%</>",
                        $similarity >= 60 => "<fg=cyan>{$similarity}%</>",
                        default => "<fg=red>{$similarity}%</>"
                    };

                    $tableRows[] = [
                        $result->getId(),
                        $scoreDisplay,
                        $result->getIntent(),
                        $this->truncate($question, 35),
                        $this->truncate($result->getQuestion(), 35),
                        $this->truncate($result->getQuery(), 35),
                        $this->truncate(implode(', ', $result->getTags()), 20),
                    ];
                }
            }

            $io->table(
                ['ID', 'Score', 'Intent', 'Question', 'Match question', 'Type query', 'Tags'],
                $tableRows
            );

            return Command::SUCCESS;
        } catch (Exception $e) {
            $io->error('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function truncate(string $text, int $length): string
    {
        return strlen($text) > $length ? substr($text, 0, $length - 3) . '...' : $text;
    }

    private function resetTable(SymfonyStyle $io): bool
    {
        try {
            $io->section('Reset de la table RAG');

            // Confirmation de sécurité
            if (!$io->confirm('Voulez-vous vraiment supprimer TOUS les exemples RAG ?', false)) {
                $io->text('Reset annulé');
                return true;
            }

            $ragConnection = $this->doctrine->getConnection('postgres');


            $ragConnection->executeStatement('DELETE FROM rag');
            $ragConnection->executeStatement('ALTER SEQUENCE rag_id_seq RESTART WITH 1');

            $io->success('✅ Table RAG vidée et séquence reset');
            return true;

        } catch (Exception $e) {
            $io->error('Erreur reset table: ' . $e->getMessage());
            return false;
        }
    }
}