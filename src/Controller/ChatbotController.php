<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidatorException;
use App\RequestHandler\Chatbot\Ask\RequestHandler;
use App\Service\Chatbot\ChatbotService;
use App\Service\LLM\IntentService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Exception;

#[Route('/api/chatbot', name: 'api_chatbot_')]
class ChatbotController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws ValidatorException
     */
    #[Route('/ask', name: 'ask', methods: ['POST'])]
    public function ask(
        Request $request,
        RequestHandler $requestHandler,
        ChatbotService $chatbotService
    ): JsonResponse {
        // Handle, validate and build data to send to LLM
        $result = $requestHandler->handle($request);

        $question = $result['question'];
        $sessionId = $result['session_id'];

        // Process the question through ChatbotService
        $result = $chatbotService->processQuestion($question);

        // Add session info if provided
        if ($sessionId) {
            $result['session_id'] = $sessionId;
        }

        $httpStatus = $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST;
        if (isset($result['error_type']) && $result['error_type'] === 'security') {
            $httpStatus = Response::HTTP_FORBIDDEN; // security
        }

        return $this->json($result, $httpStatus);
    }


    #[Route('/ask-stream-llm', name: 'ask_stream_llm', methods: ['POST'])]
    public function askStream(
        Request $request,
        RequestHandler $requestHandler,
        ChatbotService $chatbotService,
    ): Response {
        try {
            // Valider la requête
            $result = $requestHandler->handle($request);
            $question = $result['question'];

            return new StreamedResponse(function() use ($chatbotService, $question) {
                try {
                    // Step 0: Classify user intent
                    $intent = $chatbotService->classify($question);

                    // Step 1: Get database schema
                    $schema = $chatbotService->getTablesStructure();

                    // Step 2: Generate SQL query using LLM
                    $sql = $chatbotService->generateSql($question, $schema, $intent);

                    // Step 3: Validate SQL query for security
                    $validatedSql = $chatbotService->validateSql($sql);

                    // Step 4: Execute the query
                    $results = $chatbotService->executeQuery($validatedSql);

                    // ⭐ Streaming de la réponse LLM
                    foreach ($chatbotService->generateStreamingResponse($question, $results, $validatedSql, $intent) as $chunk) {
                        echo "event: llm_chunk\n";
                        echo "data: " . json_encode(['content' => $chunk]) . "\n\n";
                        flush();

                        // Petit délai pour un effet visuel plus naturel
                        usleep(50000); // 50ms
                    }

                    echo "event: llm_complete\n";
                    echo "data: " . json_encode(['finished' => true]) . "\n\n";
                    flush();

                    // ⭐ Phase 3: Gestion spécifique selon l'intent
                    if ($intent === IntentService::INTENT_DOWNLOAD && !empty($results)) {
                        $chatbotService->handleDownloadGeneration($results, $question);
                    }

                    // Signal de fin global
                    echo "event: end\n";
                    echo "data: " . json_encode(['finished' => true]) . "\n\n";
                    flush();

                } catch (Exception $e) {
                    echo "data: " . json_encode(['content' => '❌ Erreur: ' . $e->getMessage()]) . "\n\n";
                    echo "data: [DONE]\n\n";
                    flush();
                }

            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
            ]);

        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Health check endpoint
     */
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        try {
            // Simple health check - could be expanded
            return $this->json([
                'success' => true,
                'status' => 'operational',
                'timestamp' => new \DateTimeImmutable(),
                'version' => '1.0.0'
            ]);
            
        } catch (Exception $e) {
            $this->logger->error('Error in status endpoint', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Cannot retrieve system status'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
