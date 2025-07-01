<?php

namespace App\Repository\Rag;

use App\Entity\Rag\Rag;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Rag>
 *
 * @method Rag|null find($id, $lockMode = null, $lockVersion = null)
 * @method Rag|null findOneBy(array $criteria, array $orderBy = null)
 * @method Rag[]    findAll()
 * @method Rag[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rag::class);
    }

    /**
     * Find similar examples by embedding vector
     */
    public function findSimilarByEmbedding(
        array $queryEmbedding,
        ?string $intent = null,
        float $similarityThreshold = 0.7,
        int $maxResults = 5
    ): array {
        // Convert embedding array to PostgreSQL vector format
        $embeddingString = '[' . implode(',', $queryEmbedding) . ']';

        // Use DQL with custom SQL function
        $dql = '
            SELECT r,
                   (1 - COSINE_DISTANCE(r.embedding, :embedding)) as HIDDEN similarity_score
            FROM App\Entity\Rag\Rag r
            WHERE r.active = true
              AND (1 - COSINE_DISTANCE(r.embedding, :embedding)) > :threshold
        ';

        if ($intent !== null) {
            $dql .= ' AND r.intent = :intent';
        }

        $dql .= ' ORDER BY COSINE_DISTANCE(r.embedding, :embedding)';

        $query = $this->getEntityManager()->createQuery($dql);
        $query->setParameter('embedding', $embeddingString)
            ->setParameter('threshold', $similarityThreshold)
            ->setMaxResults($maxResults);

        if ($intent !== null) {
            $query->setParameter('intent', $intent);
        }

        // Execute query
        try {
            $results = $query->getResult();

            // Set similarity scores on entities
            foreach ($results as $index => $entity) {
                if ($entity instanceof Rag) {
                    // Calculate similarity manually since HIDDEN doesn't work with custom functions
                    $similarity = $this->calculateSimilarity($entity->getEmbedding(), $queryEmbedding);
                    $entity->setSimilarityScore($similarity);
                }
            }

            return $results;

        } catch (\Exception $e) {
            // Fallback to raw SQL if DQL fails
            return $this->findSimilarByEmbeddingRawSQL($queryEmbedding, $intent, $similarityThreshold, $maxResults);
        }
    }

    /**
     * Fallback method with raw SQL
     */
    private function findSimilarByEmbeddingRawSQL(
        array $queryEmbedding,
        ?string $intent,
        float $similarityThreshold,
        int $maxResults
    ): array {
        $embeddingString = '[' . implode(',', $queryEmbedding) . ']';

        $sql = '
            SELECT r.*, 
                   (1 - (r.embedding <=> :embedding)) as similarity_score
            FROM rag r
            WHERE r.active = true
              AND (1 - (r.embedding <=> :embedding)) > :threshold
        ';

        $parameters = [
            'embedding' => $embeddingString,
            'threshold' => $similarityThreshold,
        ];

        if ($intent !== null) {
            $sql .= ' AND r.intent = :intent';
            $parameters['intent'] = $intent;
        }

        $sql .= ' ORDER BY r.embedding <=> :embedding LIMIT :max_results';
        $parameters['max_results'] = $maxResults;

        $connection = $this->getEntityManager()->getConnection();
        $result = $connection->fetchAllAssociative($sql, $parameters);

        $entities = [];
        foreach ($result as $row) {
            $entity = $this->createEntityFromArray($row);
            $entity->setSimilarityScore((float) $row['similarity_score']);
            $entities[] = $entity;
        }

        return $entities;
    }

    /**
     * Calculate cosine similarity manually
     */
    private function calculateSimilarity(array $embedding1, array $embedding2): float
    {
        if (empty($embedding1) || empty($embedding2) || count($embedding1) !== count($embedding2)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;

        for ($i = 0; $i < count($embedding1); $i++) {
            $dotProduct += $embedding1[$i] * $embedding2[$i];
            $norm1 += $embedding1[$i] * $embedding1[$i];
            $norm2 += $embedding2[$i] * $embedding2[$i];
        }

        $norm1 = sqrt($norm1);
        $norm2 = sqrt($norm2);

        if ($norm1 == 0.0 || $norm2 == 0.0) {
            return 0.0;
        }

        return $dotProduct / ($norm1 * $norm2);
    }

    /**
     * Find examples by tags
     */
    public function findByTags(array $tags, ?string $intent = null): array
    {
        $qb = $this->createQueryBuilder('r');

        $qb->where('r.active = true');

        // Add tag filters
        foreach ($tags as $index => $tag) {
            $qb
                ->andWhere("r.tags LIKE :tag{$index}")
                ->setParameter("tag{$index}", "%{$tag}%");
        }

        // Add intent filter if provided
        if ($intent !== null) {
            $qb
                ->andWhere('r.intent = :intent')
                ->setParameter('intent', $intent);
        }

        return $qb->orderBy('r.usageCount', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get usage statistics
     */
    public function getUsageStats(): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.intent, COUNT(r.id) as count, AVG(r.usageCount) as avg_usage')
            ->where('r.active = true')
            ->groupBy('r.intent');

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Create entity from database array result
     */
    private function createEntityFromArray(array $data): Rag
    {
        $entity = new Rag();

        // Set basic properties
        $entity
            ->setId($data['id'])
            ->setQuestion($data['question'])
            ->setQuery($data['query'])
            ->setIntent($data['intent']);

        // Handle embedding
        if ($data['embedding']) {
            $embeddingString = trim($data['embedding'], '[]');
            $embedding = empty($embeddingString) ? [] : array_map('floatval', explode(',', $embeddingString));
            $entity->setEmbedding($embedding);
        }

        // Handle JSON fields
        if ($data['metadata']) {
            $entity->setMetadata(json_decode($data['metadata'], true) ?? []);
        }

        // Handle tags
        if ($data['tags']) {
            $tags = explode(',', $data['tags']);
            $entity->setTags($tags);
        }

        // Handle other fields
        $entity->setActive((bool) $data['active']);
        $entity->setUsageCount((int) ($data['usage_count'] ?? 0));

        // Handle date
        if ($data['created_at']) {
            $entity->setCreatedAt(new DateTimeImmutable($data['created_at']));
        }
        if ($data['updated_at']) {
            $entity->setUpdatedAt(new DateTimeImmutable($data['updated_at']));
        }

        return $entity;
    }

    /**
     * Check if an example already exists (avoid duplicates)
     */
    public function findExistingExample(string $question, string $intent): ?Rag
    {
        $connection = $this->getEntityManager()->getConnection();

        $result = $connection->fetchAssociative(
            'SELECT * FROM rag WHERE question = ? AND intent = ? AND active = true LIMIT 1',
            [$question, $intent]
        );

        if (!$result) {
            return null;
        }

        return $this->createEntityFromArray($result);
    }
}