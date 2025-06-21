<?php

namespace App\RequestHandler\Chatbot\Ask;

use App\Exception\ValidatorException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

readonly class RequestHandler
{
    public function __construct(
        private RequestValidator $requestValidator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ValidatorException
     */
    public function handle(Request $request): array
    {
        // Parse JSON input
        $data = $this->parseJsonInput($request);

        // Validate data structure
        $this->requestValidator->validate($data);

        // Build result
        $result = $this->buildResult($data);

        // Log the request
        $this->logger->info('Chatbot API request', [
            'question' => $result['question'],
            'session_id' => $result['session_id'],
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent')
        ]);

        // Return Output
        return $result;
    }

    /**
     * @throws ValidatorException
     */
    private function parseJsonInput(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ValidatorException('Format JSON invalide');
        }

        return $data ?? [];
    }

    private function buildResult(array $data): array
    {
        return [
            'question' => trim($data['question']),
            'session_id' => $data['session_id'] ?? null
        ];
    }
}