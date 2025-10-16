<?php

namespace App\Services\External;

use App\Dto\BotDto;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;
use Psr\Http\Message\ResponseInterface;
use Illuminate\Support\Facades\Log;

class BottApi
{
    const HOST = 'https://api.bot-t.com/';
    const TIMEOUT = 10;
    const CONNECT_TIMEOUT = 5;

    /**
     * Проверка $secret_key
     */
    public static function checkUser(int $telegram_id, string $secret_key, string $public_key, string $private_key)
    {
        return self::executeWithRetry(function () use ($telegram_id, $secret_key, $public_key, $private_key) {
            $requestParam = [
                'public_key' => $public_key,
                'private_key' => $private_key,
                'id' => $telegram_id,
                'secret_key' => $secret_key,
            ];

            $client = self::createClient();
            $response = $client->get(self::HOST . 'v1/module/user/check-secret?' . http_build_query($requestParam));

            return self::parseResponse($response, 'checkUser');
        }, "checkUser for user $telegram_id");
    }

    /**
     * Получение данных пользователя
     */
    public static function get(int $telegram_id, string $public_key, string $private_key)
    {
        return self::executeWithRetry(function () use ($telegram_id, $public_key, $private_key) {
            $requestParam = [
                'public_key' => $public_key,
                'private_key' => $private_key,
                'id' => $telegram_id,
            ];

            $client = self::createClient();
            $response = $client->get(self::HOST . 'v1/module/user/get?' . http_build_query($requestParam));

            return self::parseResponse($response, 'getUser');
        }, "getUser for user $telegram_id");
    }

    /**
     * Списание баланса
     */
    public static function subtractBalance(BotDto $botDto, array $userData, int $amount, string $comment)
    {
        return self::executeWithRetry(function () use ($botDto, $userData, $amount, $comment) {
            $link = self::HOST . 'v1/module/user/';
            $public_key = $botDto->public_key;
            $private_key = $botDto->private_key;
            $user_id = $userData['user']['telegram_id'];
            $secret_key = $userData['secret_user_key'];

            $requestParam = [
                'public_key' => $public_key,
                'private_key' => $private_key,
                'user_id' => $user_id,
                'secret_key' => $secret_key,
                'amount' => $amount,
                'comment' => $comment,
            ];

            $client = self::createClient();
            $response = $client->request('POST', $link . 'subtract-balance', [
                'form_params' => $requestParam,
                'headers' => [
                    'User-Agent' => 'SMS-Activate-Module/1.0',
                    'Accept' => 'application/json',
                ]
            ]);

            return self::parseResponse($response, 'subtractBalance');
        }, "subtractBalance for user {$userData['user']['telegram_id']}, amount: $amount");
    }

    /**
     * Пополнение баланса
     */
    public static function addBalance(BotDto $botDto, array $userData, int $amount, string $comment)
    {
        return self::executeWithRetry(function () use ($botDto, $userData, $amount, $comment) {
            $link = self::HOST . 'v1/module/user/';
            $public_key = $botDto->public_key;
            $private_key = $botDto->private_key;
            $user_id = $userData['user']['telegram_id'];
            $secret_key = $userData['secret_user_key'];

            $requestParam = [
                'public_key' => $public_key,
                'private_key' => $private_key,
                'user_id' => $user_id,
                'secret_key' => $secret_key,
                'amount' => $amount,
                'comment' => $comment,
            ];

            $client = self::createClient();
            $response = $client->request('POST', $link . 'add-balance', [
                'form_params' => $requestParam,
                'headers' => [
                    'User-Agent' => 'SMS-Activate-Module/1.0',
                    'Accept' => 'application/json',
                ]
            ]);

            return self::parseResponse($response, 'addBalance');
        }, "addBalance for user {$userData['user']['telegram_id']}, amount: $amount");
    }

    /**
     * Создание заказа в магазине
     */
    public static function createOrder(BotDto $botDto, array $userData, int $amount, string $product)
    {
        return self::executeWithRetry(function () use ($botDto, $userData, $amount, $product) {
            $link = self::HOST . 'v1/module/shop/';
            $public_key = $botDto->public_key;
            $private_key = $botDto->private_key;
            $user_id = $userData['user']['telegram_id'];
            $secret_key = $userData['secret_user_key'];
            $category_id = $botDto->category_id;

            $requestParam = [
                'public_key' => $public_key,
                'private_key' => $private_key,
                'user_id' => $user_id,
                'secret_key' => $secret_key,
                'amount' => $amount,
                'count' => 1,
                'category_id' => $category_id,
                'product' => $product,
            ];

            $client = self::createClient();
            $response = $client->request('POST', $link . 'order-create', [
                'form_params' => $requestParam,
                'headers' => [
                    'User-Agent' => 'SMS-Activate-Module/1.0',
                    'Accept' => 'application/json',
                ]
            ]);

            return self::parseResponse($response, 'createOrder');
        }, "createOrder for user {$userData['user']['telegram_id']}, amount: $amount");
    }

    /**
     * Получение информации о заказе
     */
    public static function getOrder(BotDto $botDto, string $orderId)
    {
        return self::executeWithRetry(function () use ($botDto, $orderId) {
            $requestParam = [
                'public_key' => $botDto->public_key,
                'private_key' => $botDto->private_key,
                'order_id' => $orderId,
            ];

            $client = self::createClient();
            $response = $client->get(self::HOST . 'v1/module/shop/order-get?' . http_build_query($requestParam));

            return self::parseResponse($response, 'getOrder');
        }, "getOrder for order $orderId");
    }

    /**
     * Получение списка заказов пользователя
     */
    public static function getUserOrders(BotDto $botDto, array $userData, int $limit = 50, int $offset = 0)
    {
        return self::executeWithRetry(function () use ($botDto, $userData, $limit, $offset) {
            $requestParam = [
                'public_key' => $botDto->public_key,
                'private_key' => $botDto->private_key,
                'user_id' => $userData['user']['telegram_id'],
                'secret_key' => $userData['secret_user_key'],
                'limit' => $limit,
                'offset' => $offset,
            ];

            $client = self::createClient();
            $response = $client->get(self::HOST . 'v1/module/shop/orders?' . http_build_query($requestParam));

            return self::parseResponse($response, 'getUserOrders');
        }, "getUserOrders for user {$userData['user']['telegram_id']}");
    }

    /**
     * Создание HTTP клиента с настройками
     */
    private static function createClient(): Client
    {
        return new Client([
            'timeout' => self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'http_errors' => true, // Бросать исключения для HTTP ошибок
            'verify' => false, // Отключить SSL верификацию для тестов (осторожно!)
            'headers' => [
                'User-Agent' => 'SMS-Activate-Module/1.0',
                'Accept' => 'application/json',
            ]
        ]);
    }

    /**
     * Парсинг ответа от API
     */
    private static function parseResponse(ResponseInterface $response, string $methodName)
    {
        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        // Логируем успешные запросы для отладки
        Log::debug("BottApi $methodName response", [
            'status_code' => $statusCode,
            'body' => $body
        ]);

        if ($statusCode !== 200) {
            throw new \RuntimeException("HTTP error $statusCode in $methodName");
        }

        $result = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON response in $methodName: " . json_last_error_msg());
        }

        if (!is_array($result)) {
            throw new \RuntimeException("Invalid response format in $methodName");
        }

        return $result;
    }

    /**
     * Выполнение с повторными попытками при ошибках
     */
    private static function executeWithRetry(callable $operation, string $operationName, int $maxRetries = 3)
    {
        $lastException = null;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $attempt++;
                Log::debug("BottApi attempt $attempt: $operationName");

                $result = $operation();

                // Если успешно с первой попытки, не логируем
                if ($attempt > 1) {
                    Log::info("BottApi $operationName succeeded on attempt $attempt");
                }

                return $result;

            } catch (ConnectException $e) {
                $lastException = $e;
                Log::warning("BottApi connection error on attempt $attempt: " . $e->getMessage());

                if ($attempt < $maxRetries) {
                    sleep(pow(2, $attempt - 1)); // Exponential backoff: 1, 2, 4 секунды
                    continue;
                }

            } catch (RequestException $e) {
                $lastException = $e;
                $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 'Unknown';

                // Для 5xx ошибок пробуем повторно, для 4xx - сразу бросаем исключение
                if ($statusCode >= 500 && $statusCode < 600 && $attempt < $maxRetries) {
                    Log::warning("BottApi server error $statusCode on attempt $attempt: " . $e->getMessage());
                    sleep(pow(2, $attempt - 1));
                    continue;
                }

                // Для 4xx ошибок (клиентские) не повторяем
                Log::error("BottApi client error $statusCode in $operationName: " . $e->getMessage());
                throw new \RuntimeException("API client error: " . $e->getMessage());

            } catch (\Exception $e) {
                $lastException = $e;
                Log::error("BottApi unexpected error in $operationName on attempt $attempt: " . $e->getMessage());

                if ($attempt < $maxRetries) {
                    sleep(pow(2, $attempt - 1));
                    continue;
                }
            }

            break;
        }

        // Если все попытки исчерпаны
        Log::error("BottApi $operationName failed after $maxRetries attempts: " . $lastException->getMessage());
        throw new \RuntimeException("Failed to execute $operationName after $maxRetries attempts: " . $lastException->getMessage());
    }

    /**
     * Пакетное выполнение нескольких запросов (для оптимизации)
     */
    public static function executeMultiple(array $operations): array
    {
        $client = self::createClient();
        $promises = [];
        $results = [];

        foreach ($operations as $key => $operation) {
            $promises[$key] = $operation($client);
        }

        try {
            $responses = Promise\Utils::settle($promises)->wait();

            foreach ($responses as $key => $response) {
                if ($response['state'] === 'fulfilled') {
                    $results[$key] = self::parseResponse($response['value'], 'batchOperation');
                } else {
                    $results[$key] = [
                        'result' => false,
                        'message' => $response['reason']->getMessage()
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::error("BottApi batch operation failed: " . $e->getMessage());
            throw new \RuntimeException("Batch operation failed: " . $e->getMessage());
        }

        return $results;
    }

    /**
     * Проверка доступности API
     */
    public static function healthCheck(): bool
    {
        try {
            $client = self::createClient();
            $response = $client->get(self::HOST . 'health', [
                'timeout' => 5,
                'connect_timeout' => 3,
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            Log::warning("BottApi health check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Получение статистики использования API
     */
    public static function getStats(): array
    {
        return [
            'timeout' => self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'host' => self::HOST,
            'health_check' => self::healthCheck(),
        ];
    }
}
