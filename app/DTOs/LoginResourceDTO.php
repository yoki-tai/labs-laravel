<?php

namespace App\DTOs;

class LoginResourceDTO
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken
    ) {}

    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken
        ];
    }
} 