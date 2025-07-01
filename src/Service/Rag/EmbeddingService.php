<?php

declare(strict_types=1);

namespace App\Service\Rag;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

readonly class EmbeddingService
{
    private const EMBEDDED_URL = 'http://127.0.0.1:5000';
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface     $logger
    ) {}

    public function getEmbedding(string $text): array
    {
        $url = self::EMBEDDED_URL . '/embed';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => ['text' => $text],
                'timeout' => 30
            ]);

            $data = $response->toArray();

            if (!isset($data['embedding']) || !is_array($data['embedding'])) {
                throw new RuntimeException('Invalid embedding response');
            }

            $this->logger->info('Generated embedding', [
                'text_length' => strlen($text),
                'embedding_dimension' => count($data['embedding'])
            ]);

            return $data['embedding'];

        } catch (Throwable $e) {
            $this->logger->error('Failed to generate embedding', [
                'error' => $e->getMessage(),
                'text' => substr($text, 0, 100)
            ]);

            return [];
        }
    }

    public function isHealthy(): bool
    {
        $url = self::EMBEDDED_URL . '/health';

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 5
            ]);

            $data = $response->toArray();

            return isset($data['status']) && $data['status'] === 'healthy';

        } catch (Throwable $e) {
            $this->logger->warning('Embedding service health check failed', [
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }
}