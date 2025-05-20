<?php

namespace App\DTOs;

class ClientInfoDTO
{
    public function __construct(
        public readonly string $ip,
        public readonly string $userAgent
    ) {}

    public function toArray(): array
    {
        return [
            'ip' => $this->ip,
            'user_agent' => $this->userAgent
        ];
    }
} 