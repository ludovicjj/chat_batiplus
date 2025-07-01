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
        $question = "Peux-tu me dire combien il y a d'avis favorables ?";

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

        // 3. Test de génération LLM
        $queryBody = $this->elasticsearchGeneratorService->generateQueryBody($normalizedQuestion, $schema, $intent);
        $io->section('2. Réponse LLM (brute):');
        $io->text($queryBody);

        // 4. Test de validation sécurité
        $this->elasticsearchSecurityService->validateQuery($queryBody);
        $io->section('3. Validation sécurité: ✅ PASSÉE');

        // 5. Test d'exécution ES
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
