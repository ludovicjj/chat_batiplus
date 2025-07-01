<?php

namespace App\Service\Rag;

use App\Entity\Rag\Rag;
use App\Repository\Rag\RagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;

readonly class RagService
{
    public function __construct(
        private EntityManagerInterface $ragEntityManager,
        private RagRepository $ragRepository,
        private EmbeddingService $embeddingService,
        private LoggerInterface $logger
    ) {}

    /**
     * Find similar examples for a given question
     */
    public function findSimilarExamples(
        string $question,
        ?string $intent = null,
        int $maxResults = 3,
        float $similarityThreshold = 0.7
    ): array {
        try {
            // Generate embedding for the question
            $questionEmbedding = $this->embeddingService->getEmbedding($question);

            if (empty($questionEmbedding)) {
                $this->logger->warning('No embedding generated for question', ['question' => $question]);
                return [];
            }

            // Use repository method for vector search
            return $this->ragRepository->findSimilarByEmbedding(
                $questionEmbedding,
                $intent,
                $similarityThreshold,
                $maxResults
            );
        } catch (Exception) {
            return [];
        }
    }

    /**
     * Add a new example to the RAG database
     */
    public function addExample(
        string $question,
        string $queryElasticsearch,
        string $intent,
        array $metadata = [],
        array $tags = []
    ): Rag {
        try {
            $existing = $this->ragRepository->findExistingExample($question, $intent);
            if ($existing) {
                return $existing;
            }

            // Generate embedding
            $embedding = $this->embeddingService->getEmbedding($question);

            if (empty($embedding)) {
                throw new RuntimeException('Failed to generate embedding for question');
            }

            // Create new Rag entity
            $rag = new Rag();
            $rag->setQuestion($question)
                ->setQuery($queryElasticsearch)
                ->setIntent($intent)
                ->setEmbedding($embedding)
                ->setMetadata($metadata)
                ->setTags($tags);

            // Persist and flush
            $this->ragEntityManager->persist($rag);
            $this->ragEntityManager->flush();

            return $rag;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to add RAG example: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Build contextual prompt from similar examples
     */
    public function buildContextualPrompt(array $examples, string $basePrompt): string
    {
        if (empty($examples)) {
            return $basePrompt;
        }

        $contextualExamples = "\n\n## Exemples similaires pertinents:\n\n";

        foreach ($examples as $index => $example) {
            /** @var Rag $example */
            $similarity = number_format(($example->getSimilarityScore() ?? 0) * 100, 1);
            $contextualExamples .= "### Exemple " . ($index + 1) . " (similarité: {$similarity}%)\n";
            $contextualExamples .= "**Question:** {$example->getQuestion()}\n";
            $contextualExamples .= "**Query Elasticsearch:**\n```json\n";
            $contextualExamples .= $example->getQuery();
            $contextualExamples .= "\n```\n\n";
        }

        $contextualExamples .= "---\n\n";
        $contextualExamples .= "Utilise ces exemples similaires comme guide pour générer une query appropriée.\n";

        return $basePrompt . $contextualExamples;
    }

    /**
     * Update usage statistics for examples
     */
    public function updateExampleUsage(array $examples): void
    {
        try {
            /** @var Rag $example */
            foreach ($examples as $example) {
                if ($example instanceof Rag) {
                    $example->incrementUsageCount();
                }
            }

            $this->ragEntityManager->flush();

            $this->logger->info('Updated example usage statistics', [
                'updated_count' => count($examples)
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update example usage', [
                'error' => $e->getMessage()
            ]);
        }
    }
}