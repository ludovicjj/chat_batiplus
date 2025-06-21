<?php

namespace App\Service\LLM;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class AbstractLLMService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(LLM_API_URL)%')] private string $apiUrl,
        #[Autowire('%env(LLM_API_KEY)%')] private string $apiKey,
        #[Autowire('%env(LLM_MODEL)%')] private string $llmModel,
        private float $temperature = 0.1,
    ) {}

    protected function callLlm(string $systemPrompt, string $userPrompt): string
    {
        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->llmModel,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt
                        ],
                        [
                            'role' => 'user',
                            'content' => $userPrompt
                        ]
                    ],
                    'temperature' => $this->temperature,
                    'reasoning_effort' => 'high'
                ],
                'timeout' => 600,
                'max_duration' => 600,
            ]);

            $data = $response->toArray();

            if (!isset($data['choices'][0]['message']['content'])) {
                throw new \RuntimeException('Invalid response from LLM API');
            }

            return trim($data['choices'][0]['message']['content']);

        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Failed to communicate with LLM service: ' . $e->getMessage());
        }
    }
}