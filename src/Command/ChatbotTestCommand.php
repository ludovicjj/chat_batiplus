<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Elasticsearch\ElasticsearchExecutorService;
use App\Service\Elasticsearch\ElasticsearchGeneratorService;
use App\Service\Elasticsearch\ElasticsearchSchemaService;
use App\Service\Elasticsearch\ElasticsearchSecurityService;
use App\Service\LLM\HumanResponseService;
use App\Service\LLM\IntentService;
use App\Service\QueryProcessor;
use App\Service\Rag\RagService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'chatbot:test',
    description: 'Test the chatbot system components'
)]
class ChatbotTestCommand extends Command
{
    public function __construct(
        private readonly IntentService $intentService,
        private readonly ElasticsearchSchemaService $elasticsearchSchemaService,
        private readonly ElasticsearchGeneratorService $elasticsearchGeneratorService,
        private readonly ElasticsearchSecurityService $elasticsearchSecurityService,
        private readonly ElasticsearchExecutorService $elasticsearchExecutorService,
        private readonly QueryProcessor $queryProcessor,
        private readonly HumanResponseService $humanResponseService,
        private readonly RagService $ragService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('ChatBot BatiPlus - Test des composants');
        // 1360 -test here (client case)
        $question = "Combien y a t il de rapports au total ?";

        $streamingData = $this->prepareElasticsearchData($io, $question);

        // 6. Response Human
        $io->section('5. Réponse humaine finale:');
        $humanResponseChunks = [];
        foreach ($this->humanResponseService->generateElasticsearchStreamingResponse(
            $question,
            $streamingData['results'],
            $streamingData['query'],
            $streamingData['intent']
        ) as $chunk) {
            $humanResponseChunks[] = $chunk;
        }

        $fullResponse = implode('', $humanResponseChunks);
        $io->newLine();
        $io->text('--- Réponse complète ---');
        $io->text($fullResponse);
        $io->success('Tests terminés');
        return Command::SUCCESS;
    }

    private function prepareElasticsearchData(SymfonyStyle $io, string $question): array
    {
        // 0. query processor
        $normalizedQuestion = $this->queryProcessor->normalizeQuestion($question);
        $io->title(sprintf('Question posé : %s', $normalizedQuestion));

        // 1: Classify user intent
        $intent = $this->intentService->classify($normalizedQuestion);
        $io->text('MODE : ' . $intent);

        if ($intent === IntentService::INTENT_CHITCHAT) {
            return [
                'intent' => $intent,
                'query' => [],
                'results' => [],
            ];
        }

        // 2. Test du schema
        $schema = $this->elasticsearchSchemaService->getMappingsStructure();

        // 3. Rag examples
        $ragExamples = [];
//        $ragExamples = $this->ragService->findSimilarExamples(
//            question: $normalizedQuestion,
//            intent: $intent,
//            similarityThreshold: 0.65
//        );
//        if (!empty($ragExamples)) {
//            foreach ($ragExamples as $index => $example) {
//                $similarity = number_format(($example->getSimilarityScore() ?? 0) * 100, 1);
//                $io->text("  📋 Exemple " . ($index + 1) . " (similarité: {$similarity}%)");
//                $io->text("     Question: \"{$example->getQuestion()}\"");
//                $io->text("     Query preview: " . substr($example->getQuery(), 0, 80) . '...');
//            }
//        } else {
//            $io->text("Failed to find similarity");
//        }

        // 4. Test de génération LLM
        $queryBody = $this->elasticsearchGeneratorService->generateQueryBody($normalizedQuestion, $schema, $intent, $ragExamples);
        $io->section('2. Réponse LLM (brute):');
        $io->text($queryBody);

        // 5. Validate ES Query
        $this->elasticsearchSecurityService->validateQuery($queryBody);
        $io->section('3. Validation sécurité: ✅ PASSÉE');

        // 6. Run ES query
        $results = $this->elasticsearchExecutorService->executeQuery($queryBody);
        $io->section('4. ES Raw result');
        $io->text(json_encode($results, JSON_PRETTY_PRINT));

        return [
            'intent' => $intent,
            'query' => $queryBody,
            'results' => $results,
        ];
    }
}
