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

        $question = "Peux-tu me dire combien il y a d'avis favorables ?";
        //$question = "Peux-tu me donner les références des rapports dans l'affaire 94P0237518 dont le manager est Patrick TNAAVA ?";
        //$question = "peux tu me donner des informations sur les rapports dans l'affaire ayant pour id 702";
        //$question = "Combien d'avis favorables y a-t-il ?";
        //$question = "Combien d'avis favorables dans l'affaire 94P0237518 ?";
        //$question = "Combien d'avis favorables dans l'affaire avec l'ID 1360 ?";
        //$question = "Combien d'avis F dans l'affaire avec la reference 94P0237518 dont le responsable d'affaire est William BAANNAAA";
        //$question = "combien a t il d'avis S dans l'affaire ayant pour ID 1360";
        //$question = "Combien a t il de report ?";
        //$question = "Combien a t il d'avis' ?";
        //$question = "Combien d'affaires pour le manager William BAANNAAA ?";
        //$question = "Combien d'affaires pour le client AP/HP - Hopital de Bicetre ?";
        //$question = "Combien d'affaires par agence ?";
        //$question = "Combien d'avis F au total ?";
        //$question = "Téléchargement les rapports de l'affaire ayant pour id 1360";
        //$question = "combien a t il d'affaires actuellement ?";
        //$question = "combien a t il de rapport dans l'affaire ayant pour titre AMELIORATION DE LA SECURITE INCENDIE SECTEUR JAUNE HOPITAL PAUL BROUSSE";

        // CRAZY Question
//        $question = "Combien d'avis violets dans l'affaire 1360 ?";
//        $question = "Combien d'avis dans l'affaire LICORNE123 ?";
//        $question = "Salut comment vas tu ?";

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
