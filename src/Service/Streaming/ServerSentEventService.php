<?php

declare(strict_types=1);

namespace App\Service\Streaming;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service dedicated to Server-Sent Events management
 * Centralizes SSE formatting, sending and timing logic
 */
class ServerSentEventService
{
    private bool $isInitialized = false;

    public function __construct(
        #[Autowire('%env(STREAMING_CHUNK_DELAY_MICROSECONDS)%')] private readonly int $chunkDelayMicroseconds = 50000,
    ) {}


    public function initializeStreaming(): void
    {
        if ($this->isInitialized) {
            return;
        }

        // Nettoyer les buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Configuration SSE
        set_time_limit(0);
        ini_set('memory_limit', '512M');
        ignore_user_abort(true);

        $this->isInitialized = true;
    }

    /**
     * Send a chunk of content to the client
     * This is what makes the text appear word by word in the chatbot
     */
    public function sendChunk(string $content): void
    {
        $this->ensureInitialized();
        $this->sendEvent('llm_chunk', ['content' => $content]);
        $this->addVisualDelay(); // Make it feel natural
    }

    /**
     * Send completion signal for LLM response
     */
    public function sendLlmComplete(): void
    {
        $this->ensureInitialized();
        $this->sendEvent('llm_complete', ['finished' => true]);
    }

    /**
     * Send download step update
     * This shows progress during ZIP generation
     */
    public function sendDownloadStep(string $message): void
    {
        $this->ensureInitialized();
        $this->sendEvent('download_step', ['message' => $message]);
    }

    /**
     * Send download ready notification
     * This tells the frontend the ZIP is ready to download
     */
    public function sendDownloadReady(array $downloadData): void
    {
        $this->ensureInitialized();
        $this->sendEvent('download_ready', ['download' => $downloadData]);
        $this->addVisualDelay();
    }

    /**
     * Send download error notification
     */
    public function sendDownloadError(array $errorData): void
    {
        $this->ensureInitialized();
        $this->sendEvent('download_error', ['download' => $errorData]);
    }

    /**
     * Send final completion signal
     * This closes the entire streaming process
     */
    public function sendFinalComplete(): void
    {
        $this->ensureInitialized();
        $this->sendEvent('end', ['finished' => true]);
    }

    /**
     * Send error message and terminate
     * This handles any unexpected errors during streaming
     */
    public function sendError(string $errorMessage): void
    {
        $this->ensureInitialized();
        $this->sendEvent('error', ['content' => '❌ Erreur: ' . $errorMessage]);
        $this->sendEvent('end', ['finished' => true, 'hasError' => true]);
    }

    /**
     * Get SSE headers for StreamedResponse
     */
    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }

    /**
     * Core method to send any SSE event
     * This is the HEART of the service - everything else uses this method
     */
    private function sendEvent(string $event, array $data): void
    {
        // Remember what we learned: event:\n then data:\n\n
        echo "event: {$event}\n";
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    /**
     * Add visual delay for better UX
     */
    private function addVisualDelay(): void
    {
        usleep($this->chunkDelayMicroseconds);
    }

    private function ensureInitialized(): void
    {
        if (!$this->isInitialized) {
            throw new \RuntimeException('SSE not initialized. Call initializeStreaming() first.');
        }
    }
}
