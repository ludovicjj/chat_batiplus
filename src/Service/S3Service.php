<?php

namespace App\Service;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class S3Service
{
    public const EXPIRATION = '+6 days';

    private S3Client $client;

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%env(AWS_S3_ENDPOINT)%')] private readonly string $s3Endpoint,
        #[Autowire('%env(AWS_S3_KEY)%')] private readonly string $s3Key,
        #[Autowire('%env(AWS_S3_SECRET)%')] private readonly string $s3Secret,
        #[Autowire('%env(AWS_S3_BUCKET)%')] private readonly string $s3Bucket,
    ) {
        $this->client = new S3Client([
            'version' => '2006-03-01',
            'endpoint' => $this->s3Endpoint,
            'region' => 'us-east-1',
            'credentials' => [
                'key' => $this->s3Key,
                'secret' => $this->s3Secret,
            ]
        ]);
    }

    /**
     * Download file content from S3
     */
    public function downloadFile(string $key): ?string
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->s3Bucket,
                'Key' => $key,
            ]);

            return $result['Body']->getContents();

        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NoSuchKey') {
                $this->logger->info('S3 file not found', ['key' => $key]);
                return null;
            }

            $this->logger->error('S3 download error', [
                'key' => $key,
                'error' => $e->getMessage(),
                'code' => $e->getAwsErrorCode()
            ]);

            return null;
        }
    }

    /**
     * Check if file exists on S3
     */
    public function fileExists(string $key): bool
    {
        try {
            $this->client->headObject([
                'Bucket' => $this->s3Bucket,
                'Key' => $key,
            ]);

            return true;

        } catch (AwsException $e) {
            return false;
        }
    }
}