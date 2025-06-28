<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Elasticsearch\ElasticsearchExecutorService;
use App\Service\Elasticsearch\ElasticsearchGeneratorService;
use App\Service\Elasticsearch\ElasticsearchSchemaService;
use App\Service\Elasticsearch\ElasticsearchSecurityService;
use App\Service\LLM\IntentService;
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

        $question = "Combien y a-t-il d'affaires ?";
        $io->title(sprintf('Question posé : %s', $question));


        // Step 0: Classify user intent
        $intent = $this->intentService->classify($question);
        $io->text('MODE : ' . $intent);
        // 1. Test du schema
        $schema = $this->elasticsearchSchemaService->getMappingsStructure();

        // 2. Test de génération LLM
        $queryBody = $this->elasticsearchGeneratorService->generateQueryBody($question, $schema, $intent);
        $io->section('2. Réponse LLM (brute):');
        $io->text(json_encode($queryBody, JSON_PRETTY_PRINT));

        // 3. Test de validation sécurité
        $validatedQuery = $this->elasticsearchSecurityService->validateQuery($queryBody);
        $io->section('3. Validation sécurité: ✅ PASSÉE');
        $io->text('Requête validée avec succès');

        // 4. Test d'exécution ES
        $results = $this->elasticsearchExecutorService->executeQuery($validatedQuery);

        dd($results);
        $io->section('4. Résultats Elasticsearch:');
        $io->text("Total: " . $results['total']);
        $io->text("Temps: " . $results['took']);

        $io->text("Résultats extraits: " . count($results['results']));
        $io->text("Structure: " . json_encode(array_keys($results), JSON_PRETTY_PRINT));

        $io->success('Tests terminés');
        return Command::SUCCESS;
    }

    private function extractJsonFromResponse(string $response): array
    {
        // Remove markdown code blocks if present
        $response = preg_replace('/```json\s*/', '', $response);
        $response = preg_replace('/```\s*/', '', $response);
        $response = trim($response);

        // Try to decode JSON
        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // If JSON parsing fails, try to extract JSON from mixed content
            $pattern = '/\{.*\}/s';
            if (preg_match($pattern, $response, $matches)) {
                $decoded = json_decode($matches[0], true);
            }

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response from LLM: ' . json_last_error_msg() . "\nResponse: " . $response);
            }
        }

        return $decoded;
    }
}
