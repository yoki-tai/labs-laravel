<?php

namespace App\Http\Controllers;

use App\DTOs\ClientInfoDTO;
use App\DTOs\DatabaseInfoDTO;
use App\DTOs\ServerInfoDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InfoController extends Controller
{
    public function serverInfo(): JsonResponse
    {
        $dto = new ServerInfoDTO(
            phpVersion: PHP_VERSION,
            phpInfo: [
                'version' => PHP_VERSION,
                'os' => PHP_OS,
                'extensions' => get_loaded_extensions(),
                'ini_settings' => ini_get_all()
            ]
        );

        return response()->json($dto->toArray());
    }

    public function clientInfo(Request $request): JsonResponse
    {
        $dto = new ClientInfoDTO(
            ip: $request->ip(),
            userAgent: $request->userAgent()
        );

        return response()->json($dto->toArray());
    }

    public function databaseInfo(): JsonResponse
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        $dto = new DatabaseInfoDTO(
            driver: $config['driver'],
            database: $config['database'],
            host: $config['host'],
            port: $config['port']
        );

        return response()->json($dto->toArray());
    }
}
