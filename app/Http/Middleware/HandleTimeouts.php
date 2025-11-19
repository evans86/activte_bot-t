<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleTimeouts
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            \Log::warning('Connection timeout in middleware', [
                'url' => $request->fullUrl(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'result' => false,
                'message' => 'Временные проблемы с соединением. Попробуйте позже.'
            ], 200); // 200 чтобы фронтенд не считал это ошибкой
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            \Log::warning('Request timeout in middleware', [
                'url' => $request->fullUrl(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'result' => false,
                'message' => 'Сервис временно недоступен. Попробуйте через несколько минут.'
            ], 200);
        }
    }
}
