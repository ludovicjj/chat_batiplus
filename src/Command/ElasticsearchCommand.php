<?php

namespace App\Command;

use App\Dto\ClientCaseDto;
use App\Service\Elasticsearch\ElasticsearchIndexerService;
use App\Service\Elasticsearch\StreamingDataFetcher;
use App\Service\ElasticsearchSearchService;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:es',
    description: 'Test Elasticsearch connection'
)]
class ElasticsearchCommand extends Command
{
    public function __construct(
        private readonly StreamingDataFetcher $streamingDataFetcher,
        private readonly ElasticsearchSearchService $elasticsearchSearchService,
        private readonly ElasticsearchIndexerService $elasticsearchIndexerService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limit number of records', 1)
            ->addOption('action', null, InputOption::VALUE_REQUIRED, 'action to execute')
            ->addOption('id', null, InputOption::VALUE_OPTIONAL, 'Specific ClientCase ID to test')
            ->addOption('start-from-id', null, InputOption::VALUE_OPTIONAL, 'Restart indexing from this client case ID')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force action without confirmation');
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getOption('action');

        return match ($action) {
            'index' => $this->createIndex($io),
            'delete' => $this->deleteIndex($io),
            'es-index-one' => $this->indexSingleClientCase($io, $input),
            'es-index-all' => $this->indexAllWithResume($io, $input),
            default => throw new Exception('Invalid action')
        };
    }

    public function createIndex(SymfonyStyle $io): int
    {
        $this->elasticsearchSearchService->createIndex('client_case');
        $io->success('Index created');
        return Command::SUCCESS;
    }

    public function deleteIndex(SymfonyStyle $io): int
    {
        $this->elasticsearchSearchService->deleteIndex('client_case');
        $io->success('Index deleted');
        return Command::SUCCESS;
    }

    public function indexSingleClientCase(SymfonyStyle $io, InputInterface $input): int
    {
        try {
            ini_set('memory_limit', '-1');
            $clientCaseId = $input->getOption('id');

            if (!$clientCaseId) {
                throw new Exception('Client Case ID is required');
            }

            $this->elasticsearchIndexerService->indexSingleClientCase($clientCaseId);
            $io->success(sprintf("Index with success clientCase : %d", $clientCaseId));
            return Command::SUCCESS;
        } catch (Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function indexAllWithResume(SymfonyStyle $io, InputInterface $input): int
    {
        $io->title('🚀 Start to index all client case with resume');

        $startFromId = $input->getOption('start-from-id');

        try {
            $results = $this->elasticsearchIndexerService->indexAllClientCases($io, $startFromId);

            if ($results['total_errors'] === 0) {
                $io->success('✅ Indexation terminée avec succès !');
            } else {
                $io->warning("⚠️ Indexation terminée avec {$results['total_errors']} erreur(s).");
            }

            return $results['total_errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
        } catch (Exception $e) {
            $io->error('❌ Erreur: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Test Build DTO
     */
    private function testSingleClientCaseStreaming(SymfonyStyle $io, InputInterface $input): int
    {
        ini_set('memory_limit', '-1');
        $io->title('🧪 Test Streaming - 1 ClientCase');

        // Utiliser l'ID fourni ou 869 par défaut
        $clientCaseId = $input->getOption('id') ?? 1315;
        $io->section("🎯 Test du ClientCase ID: {$clientCaseId}");

        $startTime = microtime(true);
        $memoryBefore = memory_get_usage(true);
        $riskAnalysis = $this->streamingDataFetcher->analyzeClientCaseRisk($clientCaseId);
        $io->table(['Analyse préalable', 'Valeur'], [
            ['Nombre de reports', $riskAnalysis['stats']['reports_count']],
            ['Total reviews', $riskAnalysis['stats']['total_reviews']],
            ['Max reviews/report', $riskAnalysis['stats']['max_reviews_per_report']],
            ['Niveau de risque', $riskAnalysis['risk_level']]
        ]);

        $startTime = microtime(true);
        $memoryBefore = memory_get_usage(true);
        $peakMemory = $memoryBefore;

        try {
            $clientCase = $this->streamingDataFetcher->fetchClientCaseComplete($clientCaseId);
            $peakMemory = memory_get_peak_usage(true);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            $memoryAfter = memory_get_usage(true);
            $memoryUsed = round(($memoryAfter - $memoryBefore) / 1024 / 1024, 2);
            $peakMemoryMB = round($peakMemory / 1024 / 1024, 2);

            if (!$clientCase) {
                $io->error("❌ ClientCase {$clientCaseId} non trouvé");
                return Command::FAILURE;
            }

            $reportsCount = count($clientCase['reports'] ?? []);
            $totalReviews = array_sum(array_map(fn($r) => count($r['reviews'] ?? []), $clientCase['reports'] ?? []));

            // Calculer la taille moyenne d'un avis
            $avgReviewSize = $totalReviews > 0 ? round(($memoryUsed * 1024 * 1024) / $totalReviews, 0) : 0;

            $io->section('📊 Résultats du test');
            $io->table(['Métrique', 'Valeur'], [
                ['ClientCase ID', $clientCase['id']],
                ['Référence', $clientCase['reference']],
                ['Nombre de Reports', number_format($reportsCount)],
                ['Nombre total de Reviews', number_format($totalReviews)],
                ['Temps d\'exécution', $executionTime . ' ms'],
                ['Mémoire utilisée', $memoryUsed . ' MB'],
                ['Pic mémoire', $peakMemoryMB . ' MB'],
                ['Mémoire limite PHP', ini_get('memory_limit')],
                ['Taille moy./avis', $avgReviewSize . ' bytes'],
                ['Mode traitement', $clientCase['_mode'] ?? 'COMPLETE']
            ]);

            // Proposer de voir les données brutes
            if ($io->confirm('Voulez-vous voir la structure complète ?', false)) {
                $io->section('🗃️ Structure complète du ClientCase');
                $dto = ClientCaseDto::fromStreamingData($clientCase);
                $document = $dto->toElasticsearchDocument();
                dd($document);
            }

            $io->success('✅ Test build DTO !');
            return Command::SUCCESS;
        } catch (Exception $exception) {
            $io->error('❌ Erreur pendant le test: ' . $exception->getMessage());
            $io->note('Stack trace: ' . $exception->getTraceAsString());
            return Command::FAILURE;
        }
    }
}