<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidatorException;
use App\RequestHandler\Chatbot\Ask\RequestHandler;
use App\Service\Chatbot\ChatbotService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
