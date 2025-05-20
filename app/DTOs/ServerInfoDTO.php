<?php

namespace App\DTOs;

class ServerInfoDTO
{
    public function __construct(
        public readonly string $phpVersion,
        public readonly array $phpInfo
    ) {}

    public function toArray(): array
    {
        return [
            'php_version' => $this->phpVersion,
            'php_info' => $this->phpInfo
        ];
    }
} 