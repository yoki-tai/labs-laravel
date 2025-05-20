<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Http\Requests\LoginRequest;
use App\DTOs\RegisterResourceDTO;
use App\DTOs\LoginResourceDTO;
use App\DTOs\UserResourceDTO;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    // Регистрация
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        return response()->json($request->resource($user)->toArray(), 201);
    }

    // Авторизация
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Неверный email или пароль'], 401);
        }
        // Генерация токенов (access и refresh)
        $accessToken = $this->generateToken($user, 'access');
        $refreshToken = $this->generateToken($user, 'refresh');
        // Сохраняем токены в кэше (или в памяти)
        $this->storeToken($user, $accessToken, 'access');
        $this->storeToken($user, $refreshToken, 'refresh');
        return response()->json($request->resource($accessToken, $refreshToken)->toArray(), 200);
    }

    // Генерация токена (JWT-like, вручную)
    private function generateToken(User $user, string $type): string
    {
        $ttl = $type === 'access'
            ? (int) env('ACCESS_TOKEN_TTL', 60)
            : (int) env('REFRESH_TOKEN_TTL', 43200);
        $payload = [
            'uid' => $user->id,
            'type' => $type,
            'exp' => now()->addMinutes($ttl)->timestamp,
            'rand' => Str::random(32),
        ];
        return base64_encode(json_encode($payload));
    }

    // Сохраняем токен в кэше (или в памяти) с ограничением по количеству
    private function storeToken(User $user, string $token, string $type): void
    {
        $key = "user:{$user->id}:tokens:{$type}";
        $tokens = Cache::get($key, []);
        $max = (int) env('MAX_TOKENS_PER_USER', 5);
        // Если превышено количество, удаляем самый старый токен
        if (count($tokens) >= $max) {
            array_shift($tokens);
        }
        $tokens[] = $token;
        $ttl = $type === 'access'
            ? (int) env('ACCESS_TOKEN_TTL', 60)
            : (int) env('REFRESH_TOKEN_TTL', 43200);
        Cache::put($key, $tokens, now()->addMinutes($ttl));
    }

    // Получение информации о пользователе
    public function user(Request $request): JsonResponse
    {
        $user = $this->getUserFromToken($request);
        if (!$user) {
            return response()->json(['message' => 'Неавторизовано'], 401);
        }
        $dto = new UserResourceDTO($user->id, $user->name, $user->email);
        return response()->json($dto->toArray(), 200);
    }

    // Разлогирование (отзыв текущего access и refresh токена)
    public function logout(Request $request): JsonResponse
    {
        $user = $this->getUserFromToken($request);
        if (!$user) {
            return response()->json(['message' => 'Неавторизовано'], 401);
        }
        $this->revokeToken($user, $request, 'access');
        $this->revokeToken($user, $request, 'refresh');
        return response()->json(['message' => 'Вы вышли из системы'], 200);
    }

    // Получение списка токенов пользователя
    public function tokens(Request $request): JsonResponse
    {
        $user = $this->getUserFromToken($request);
        if (!$user) {
            return response()->json(['message' => 'Неавторизовано'], 401);
        }
        $accessTokens = Cache::get("user:{$user->id}:tokens:access", []);
        $refreshTokens = Cache::get("user:{$user->id}:tokens:refresh", []);
        return response()->json([
            'access_tokens' => $accessTokens,
            'refresh_tokens' => $refreshTokens
        ], 200);
    }

    // Отзыв всех токенов пользователя
    public function revokeAll(Request $request): JsonResponse
    {
        $user = $this->getUserFromToken($request);
        if (!$user) {
            return response()->json(['message' => 'Неавторизовано'], 401);
        }
        Cache::forget("user:{$user->id}:tokens:access");
        Cache::forget("user:{$user->id}:tokens:refresh");
        return response()->json(['message' => 'Все токены отозваны'], 200);
    }

    // Обновление токена (refresh)
    public function refresh(Request $request): JsonResponse
    {
        $refreshToken = $request->bearerToken();
        $payload = $this->decodeToken($refreshToken);
        if (!$payload || $payload['type'] !== 'refresh' || $payload['exp'] < time()) {
            return response()->json(['message' => 'Недействительный refresh токен'], 401);
        }
        $user = User::find($payload['uid']);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }
        $refreshTokens = Cache::get("user:{$user->id}:tokens:refresh", []);
        if (!in_array($refreshToken, $refreshTokens)) {
            return response()->json(['message' => 'Токен уже был использован или не найден'], 401);
        }
        // Удаляем использованный refresh токен
        $refreshTokens = array_filter($refreshTokens, fn($t) => $t !== $refreshToken);
        Cache::put("user:{$user->id}:tokens:refresh", $refreshTokens, now()->addDays(30));
        // Генерируем новую пару токенов
        $accessToken = $this->generateToken($user, 'access');
        $newRefreshToken = $this->generateToken($user, 'refresh');
        $this->storeToken($user, $accessToken, 'access');
        $this->storeToken($user, $newRefreshToken, 'refresh');
        return response()->json([
            'access_token' => $accessToken,
            'refresh_token' => $newRefreshToken
        ], 200);
    }

    // Смена пароля
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->getUserFromToken($request);
        if (!$user) {
            return response()->json(['message' => 'Неавторизовано'], 401);
        }
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);
        if (!Hash::check($request->input('current_password'), $user->password)) {
            return response()->json(['message' => 'Неверный текущий пароль'], 422);
        }
        $user->password = Hash::make($request->input('new_password'));
        $user->save();
        return response()->json(['message' => 'Пароль успешно изменён'], 200);
    }

    // --- Вспомогательные методы ---
    private function getUserFromToken(Request $request): ?User
    {
        $token = $request->bearerToken();
        $payload = $this->decodeToken($token);
        if (!$payload || $payload['type'] !== 'access' || $payload['exp'] < time()) {
            return null;
        }
        $user = User::find($payload['uid']);
        $tokens = Cache::get("user:{$payload['uid']}:tokens:access", []);
        if (!in_array($token, $tokens)) {
            return null;
        }
        return $user;
    }

    private function decodeToken($token): ?array
    {
        $json = base64_decode($token);
        $payload = json_decode($json, true);
        return is_array($payload) ? $payload : null;
    }

    private function revokeToken(User $user, Request $request, string $type): void
    {
        $token = $request->bearerToken();
        $key = "user:{$user->id}:tokens:{$type}";
        $tokens = Cache::get($key, []);
        $tokens = array_filter($tokens, fn($t) => $t !== $token);
        Cache::put($key, $tokens, now()->addDays(30));
    }
}
