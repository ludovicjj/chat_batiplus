<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Elasticsearch\ElasticsearchExecutorService;
use App\Service\Elasticsearch\ElasticsearchGeneratorService;
use App\Service\Elasticsearch\ElasticsearchSchemaService;
use App\Service\Elasticsearch\ElasticsearchSecurityService;
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

        //$question = "Peux-tu me donner les avis favorables ?";
        //$question = "Peux-tu me donner les références des rapports dans l'affaire 94P0237518 dont le manager est Patrick TNAAVA ?";
        //$question = "peux tu me donner des informations sur les rapports dans l'affaire ayant pour id 702";
        //$question = "Combien d'avis favorables y a-t-il ?";
        //$question = "Combien d'avis favorables dans l'affaire 94P0237518 ?";
        //$question = "Combien d'avis favorables dans l'affaire avec l'ID 1360 ?";
        //$question = "Combien d'avis L dans l'affaire avec la reference 94P0237518 dont le responsable d'affaire est William BAANNAAA";
        //$question = "combien a t il d'avis S dans l'affaire ayant pour ID 1360";
        //$question = "Combien a t il de report ?";
        //$question = "Combien a t il d'avis' ?";
        $question = "Combien d'affaires pour le manager William BAANNAAA ?";
        $question = "Combien d'affaires pour le client AP/HP - Hopital de Bicetre ?";
        $question = "Combien d'affaires par agence ?";
        $question = "Combien d'avis F au total ?";
        $question = "Téléchargement les rapports de l'affaire ayant pour id 1360";


        // 0. query processor
        $normalizedQuestion = $this->queryProcessor->normalizeQuestion($question);
        $io->title(sprintf('Question posé : %s', $normalizedQuestion));

        // 1: Classify user intent
        $intent = $this->intentService->classify($normalizedQuestion);
        $io->text('MODE : ' . $intent);

        // 2. Test du schema
        $schema = $this->elasticsearchSchemaService->getMappingsStructure();
        //$io->text(json_encode($schema, JSON_PRETTY_PRINT));

        // 3. Test de génération LLM
        $queryBody = $this->elasticsearchGeneratorService->generateQueryBody($normalizedQuestion, $schema, $intent);
        $io->section('2. Réponse LLM (brute):');
        $io->text(json_encode($queryBody, JSON_PRETTY_PRINT));

        // 4. Test de validation sécurité
        $validatedQuery = $this->elasticsearchSecurityService->validateQuery($queryBody);
        $io->section('3. Validation sécurité: ✅ PASSÉE');
        $io->text('Requête validée avec succès');

        // 5. Test d'exécution ES
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
