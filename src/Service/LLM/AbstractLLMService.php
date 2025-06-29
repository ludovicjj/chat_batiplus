<?php

namespace App\Service\LLM;

use Generator;
use JsonException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

abstract class AbstractLLMService
{
    private const DATA_PREFIX = 'data: ';

    public function __construct(
        private HttpClientInterface                             $httpClient,
        #[Autowire('%env(LLM_API_URL)%')] private string        $apiUrl,
        #[Autowire('%env(LLM_API_KEY)%')] private string        $apiKey,
        #[Autowire('%env(LLM_MODEL)%')] private string          $llmModel,
        #[Autowire('%kernel.project_dir%')] protected string    $projectDir,
        private float                                           $temperature = 0.1,
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
                    //'reasoning_effort' => 'high'
                ],
                'timeout' => 600,
                'max_duration' => 600,
            ]);

            $data = $response->toArray();

            if (!isset($data['choices'][0]['message']['content'])) {
                throw new RuntimeException('Invalid response from LLM API');
            }

            return trim($data['choices'][0]['message']['content']);

        } catch (Throwable $e) {
            throw new RuntimeException('Failed to communicate with LLM service: ' . $e->getMessage());
        }
    }

    /**
     * Call LLM with streaming response
     */
    protected function callLlmStreaming(string $systemPrompt, string $userPrompt): Generator
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
                    'reasoning_effort' => 'high',
                    'stream' => true
                ],
                'buffer' => false,
                'timeout' => 600,
                'max_duration' => 600,
            ]);

            // Stream each chucks send by LLM
            foreach ($this->httpClient->stream($response) as $chunk) {
                $content = $chunk->getContent();

                // Parser le format Server-Sent Events d'OpenAI
                $lines = explode("\n", $content);

                foreach ($lines as $line) {
                    if (str_starts_with($line, self::DATA_PREFIX)) {
                        // remove prefix 'data: '
                        $data = substr($line, strlen(self::DATA_PREFIX));

                        // Si c'est la fin du stream
                        if (trim($data) === '[DONE]') {
                            return; // end here
                        }

                        // Parse Json
                        try {
                            $json = json_decode(trim($data), true, 512, JSON_THROW_ON_ERROR);

                            // Fetch content from chunk
                            if (isset($json['choices'][0]['delta']['content'])) {
                                $textChunk = $json['choices'][0]['delta']['content'];
                                yield $textChunk;
                            }

                        } catch (JsonException) {
                            continue;
                        }
                    }
                }
            }

        } catch (Throwable $e) {
            yield "Erreur lors de la génération de la réponse: " . $e->getMessage();
        }
    }
}