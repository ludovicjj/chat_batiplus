<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ChatbotService;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/chatbot', name: 'api_chatbot_')]
class ChatbotController extends AbstractController
{
    public function __construct(
        private readonly ChatbotService $chatbotService,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Main endpoint for processing chatbot questions
     */
    #[Route('/ask', name: 'ask', methods: ['POST'])]
    public function ask(Request $request): JsonResponse
    {
        try {
            // Parse JSON input
            $data = json_decode($request->getContent(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json([
                    'success' => false,
                    'error' => 'Format JSON invalide'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate input structure
            $violations = $this->validator->validate($data, new Assert\Collection([
                'question' => [
                    new Assert\NotBlank(message: 'La question ne peut pas être vide'),
                    new Assert\Type('string', message: 'La question doit être une chaîne de caractères'),
                    new Assert\Length(
                        min: 3,
                        max: 500,
                        minMessage: 'La question doit contenir au moins {{ limit }} caractères',
                        maxMessage: 'La question ne peut pas dépasser {{ limit }} caractères'
                    )
                ],
                'session_id' => [
                    new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Length(max: 100)
                    ])
                ]
            ]));

            if (count($violations) > 0) {
                $errors = [];
                foreach ($violations as $violation) {
                    $errors[] = $violation->getMessage();
                }
                
                return $this->json([
                    'success' => false,
                    'error' => 'Données invalides',
                    'details' => $errors
                ], Response::HTTP_BAD_REQUEST);
            }

            $question = trim($data['question']);
            $sessionId = $data['session_id'] ?? null;

            // Log the request
            $this->logger->info('Chatbot API request', [
                'question' => $question,
                'session_id' => $sessionId,
                'ip' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent')
            ]);

            // Process the question through ChatbotService
            $result = $this->chatbotService->processQuestion($question);

            // Add session info if provided
            if ($sessionId) {
                $result['session_id'] = $sessionId;
            }

            // Return appropriate HTTP status based on result
            $httpStatus = $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST;
            if (isset($result['error_type']) && $result['error_type'] === 'security') {
                $httpStatus = Response::HTTP_FORBIDDEN;
            }

            return $this->json($result, $httpStatus);

        } catch (Exception $e) {
            $this->logger->error('Unexpected error in chatbot API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_content' => $request->getContent()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Une erreur inattendue s\'est produite'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
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
