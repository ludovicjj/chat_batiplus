<?php

namespace App\Dto;

readonly class ClientCaseDto
{
    public function __construct(
        public int     $id,
        public ?string $reference,
        public ?string $projectName,
        public ?string  $agencyName,
        public ?string  $clientName,
        public ?string  $statusName,
        public ?string $managerName
    ) {}

    // Create from raw database array
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            reference: $data['reference'] ?? null,
            projectName: $data['project_name'] ?? null,
            agencyName: $data['agency_name'] ?? null,
            clientName: $data['client_name'] ?? null,
            statusName: $data['status_name'] ?? null,
            managerName: $data['manager_name'] ?? null,
        );
    }

    // Convert to array for Elasticsearch
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'projectName' => $this->projectName,
            'agencyName' => $this->agencyName,
            'clientName' => $this->clientName,
            'statusName' => $this->statusName,
            'managerName' => $this->managerName,
        ];
    }
}