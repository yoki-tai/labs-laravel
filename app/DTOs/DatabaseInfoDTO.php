<?php

namespace App\DTOs;

class DatabaseInfoDTO
{
    public function __construct(
        public readonly string $driver,
        public readonly string $database,
        public readonly string $host,
        public readonly int $port
    ) {}

    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'database' => $this->database,
            'host' => $this->host,
            'port' => $this->port
        ];
    }
} 