<?php

namespace App\Services\External;

use App\Dto\BotDto;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Log;

class BottApi
{
    /**
     * Создание заказа в bot-t
     */
    public static function createOrder(BotDto $botDto, array $userData, int $amount, string $product)
    {
        try {
            $client = new Client([
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);

            \Log::info('Creating order in bot-t', [
                'user_id' => $userData['user']['telegram_id'],
                'amount' => $amount,
                'product' => $product
            ]);

            $response = $client->post('https://api.bot-t.com/v1/module/shop/order-create', [
                'form_params' => [
                    'public_key' => $botDto->public_key,
                    'private_key' => $botDto->private_key,
                    'user_id' => $userData['user']['telegram_id'],
                    'secret_key' => $userData['secret_user_key'],
                    'amount' => $amount,
                    'count' => 1,
                    'category_id' => $botDto->category_id,
                    'product' => $product,
                ],
            ]);

            $content = $response->getBody()->getContents();

            if (empty($content)) {
                throw new \RuntimeException('Empty response from bot-t API');
            }

            $result = json_decode($content, true);

            if (!is_array($result)) {
                throw new \RuntimeException('Invalid JSON response from bot-t API');
            }

            if (!isset($result['result'])) {
                throw new \RuntimeException('Missing "result" key in bot-t API response');
            }

            \Log::info('bot-t API response', [
                'result' => $result['result'],
                'message' => $result['message'] ?? 'No message',
                'order_id' => $result['data']['order_id'] ?? 'unknown'
            ]);

            if (!$result['result']) {
                $message = $result['message'] ?? 'Unknown error from bot-t';
                throw new \RuntimeException($message);
            }

            return $result;

        } catch (GuzzleException $e) {
            \Log::error('Guzzle exception in bot-t API', [
                'error' => $e->getMessage(),
                'user_id' => $userData['user']['telegram_id'] ?? 'unknown'
            ]);
            throw new \RuntimeException('Ошибка связи с bot-t: ' . $e->getMessage());
        } catch (\Exception $e) {
            \Log::error('General exception in bot-t API', [
                'error' => $e->getMessage(),
                'user_id' => $userData['user']['telegram_id'] ?? 'unknown'
            ]);
            throw new \RuntimeException('Ошибка обработки ответа от bot-t: ' . $e->getMessage());
        }
    }

    private static function createClient(): Client
    {
        $stack = HandlerStack::create();

        // Добавляем retry middleware
        $stack->push(Middleware::retry(
            function (
                $retries,
                Request $request,
                Response $response = null,
                RequestException $exception = null
            ) {
                // Максимум 3 попытки
                if ($retries >= 3) {
                    return false;
                }

                // Повторяем при таймаутах и серверных ошибках
                if ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
                    Log::warning("Retrying request due to connection issue", [
                        'retry' => $retries + 1,
                        'url' => $request->getUri()
                    ]);
                    return true;
                }

                if ($response && $response->getStatusCode() >= 500) {
                    Log::warning("Retrying request due to server error", [
                        'retry' => $retries + 1,
                        'status' => $response->getStatusCode()
                    ]);
                    return true;
                }

                return false;
            },
            function ($retries) {
                // Экспоненциальная задержка
                return 1000 * pow(2, $retries);
            }
        ));

        return new Client([
            'timeout' => 15, // Увеличиваем общий таймаут
            'connect_timeout' => 8, // Увеличиваем таймаут подключения
            'handler' => $stack,
            'curl' => [
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE => 10,
                CURLOPT_TCP_KEEPINTVL => 5,
            ]
        ]);
    }

    public static function checkUser(int $telegram_id, string $secret_key, string $public_key, string $private_key)
    {
        $maxRetries = 2;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $client = self::createClient();

                $response = $client->get('https://api.bot-t.com/v1/module/user/check-secret?' . http_build_query([
                        'public_key' => $public_key,
                        'private_key' => $private_key,
                        'id' => $telegram_id,
                        'secret_key' => $secret_key,
                    ]));

                $content = $response->getBody()->getContents();

                if (empty($content)) {
                    throw new \RuntimeException('Empty response from bot-t API');
                }

                $result = json_decode($content, true);

                if (!is_array($result)) {
                    throw new \RuntimeException('Invalid JSON response from bot-t API');
                }

                Log::info("Bot-t API checkUser successful", [
                    'attempt' => $attempt,
                    'user_id' => $telegram_id,
                    'result' => $result['result'] ?? false
                ]);

                return $result;

            } catch (GuzzleException $e) {
                $lastException = $e;
                Log::warning("Bot-t API checkUser attempt failed", [
                    'attempt' => $attempt,
                    'user_id' => $telegram_id,
                    'error' => $e->getMessage()
                ]);

                if ($attempt < $maxRetries) {
                    sleep(1); // Ждем перед повторной попыткой
                    continue;
                }
            } catch (\Exception $e) {
                $lastException = $e;
                Log::error("Bot-t API checkUser error", [
                    'attempt' => $attempt,
                    'user_id' => $telegram_id,
                    'error' => $e->getMessage()
                ]);

                if ($attempt < $maxRetries) {
                    sleep(1);
                    continue;
                }
            }
        }

        // Если все попытки неудачны, логируем и возвращаем мягкую ошибку
        Log::error("All bot-t API checkUser attempts failed", [
            'user_id' => $telegram_id,
            'final_error' => $lastException ? $lastException->getMessage() : 'Unknown error'
        ]);

        // Возвращаем мягкую ошибку вместо исключения
        return [
            'result' => false,
            'message' => 'Временные проблемы с соединением. Попробуйте позже.'
        ];
    }

//    /**
//     * Проверка пользователя
//     */
//    public static function checkUser(int $telegram_id, string $secret_key, string $public_key, string $private_key)
//    {
//        try {
//            $client = new Client([
//                'timeout' => 5,
//                'connect_timeout' => 3,
//            ]);
//
//            $response = $client->get('https://api.bot-t.com/v1/module/user/check-secret?' . http_build_query([
//                    'public_key' => $public_key,
//                    'private_key' => $private_key,
//                    'id' => $telegram_id,
//                    'secret_key' => $secret_key,
//                ]));
//
//            $content = $response->getBody()->getContents();
//
//            if (empty($content)) {
//                throw new \RuntimeException('Empty response from bot-t API');
//            }
//
//            $result = json_decode($content, true);
//
//            if (!is_array($result)) {
//                throw new \RuntimeException('Invalid JSON response from bot-t API');
//            }
//
//            return $result;
//
//        } catch (GuzzleException $e) {
//            throw new \RuntimeException('Ошибка связи с bot-t: ' . $e->getMessage());
//        } catch (\Exception $e) {
//            throw new \RuntimeException('Ошибка проверки пользователя: ' . $e->getMessage());
//        }
//    }

    /**
     * Списание баланса
     */
    public static function subtractBalance(BotDto $botDto, array $userData, int $amount, string $comment)
    {
        try {
            $client = new Client([
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);

            $response = $client->post('https://api.bot-t.com/v1/module/user/subtract-balance', [
                'form_params' => [
                    'public_key' => $botDto->public_key,
                    'private_key' => $botDto->private_key,
                    'user_id' => $userData['user']['telegram_id'],
                    'secret_key' => $userData['secret_user_key'],
                    'amount' => $amount,
                    'comment' => $comment,
                ],
            ]);

            $content = $response->getBody()->getContents();

            if (empty($content)) {
                throw new \RuntimeException('Empty response from bot-t API');
            }

            $result = json_decode($content, true);

            if (!is_array($result)) {
                throw new \RuntimeException('Invalid JSON response from bot-t API');
            }

            if (!isset($result['result'])) {
                throw new \RuntimeException('Missing "result" key in bot-t API response');
            }

            if (!$result['result']) {
                $message = $result['message'] ?? 'Unknown error from bot-t';
                throw new \RuntimeException($message);
            }

            return $result;

        } catch (GuzzleException $e) {
            throw new \RuntimeException('Ошибка списания баланса: ' . $e->getMessage());
        } catch (\Exception $e) {
            throw new \RuntimeException('Ошибка обработки списания баланса: ' . $e->getMessage());
        }
    }

    /**
     * Пополнение баланса
     */
    public static function addBalance(BotDto $botDto, array $userData, int $amount, string $comment)
    {
        try {
            $client = new Client([
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);

            $response = $client->post('https://api.bot-t.com/v1/module/user/add-balance', [
                'form_params' => [
                    'public_key' => $botDto->public_key,
                    'private_key' => $botDto->private_key,
                    'user_id' => $userData['user']['telegram_id'],
                    'secret_key' => $userData['secret_user_key'],
                    'amount' => $amount,
                    'comment' => $comment,
                ],
            ]);

            $content = $response->getBody()->getContents();

            if (empty($content)) {
                throw new \RuntimeException('Empty response from bot-t API');
            }

            $result = json_decode($content, true);

            if (!is_array($result)) {
                throw new \RuntimeException('Invalid JSON response from bot-t API');
            }

            return $result;

        } catch (GuzzleException $e) {
            throw new \RuntimeException('Ошибка пополнения баланса: ' . $e->getMessage());
        } catch (\Exception $e) {
            throw new \RuntimeException('Ошибка обработки пополнения баланса: ' . $e->getMessage());
        }
    }

    /**
     * Универсальный метод для получения данных пользователя
     */
    public static function get(int $telegram_id, string $public_key, string $private_key)
    {
        try {
            $client = new Client([
                'timeout' => 5,
                'connect_timeout' => 3,
            ]);

            $response = $client->get('https://api.bot-t.com/v1/module/user/get?' . http_build_query([
                    'public_key' => $public_key,
                    'private_key' => $private_key,
                    'id' => $telegram_id,
                ]));

            $content = $response->getBody()->getContents();

            if (empty($content)) {
                throw new \RuntimeException('Empty response from bot-t API');
            }

            $result = json_decode($content, true);

            if (!is_array($result)) {
                throw new \RuntimeException('Invalid JSON response from bot-t API');
            }

            return $result;

        } catch (GuzzleException $e) {
            throw new \RuntimeException('Ошибка связи с bot-t: ' . $e->getMessage());
        } catch (\Exception $e) {
            throw new \RuntimeException('Ошибка получения данных пользователя: ' . $e->getMessage());
        }
    }
}
