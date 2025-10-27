<?php

namespace App\Services\External;

use App\Dto\BotDto;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

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

    /**
     * Проверка пользователя
     */
    public static function checkUser(int $telegram_id, string $secret_key, string $public_key, string $private_key)
    {
        try {
            $client = new Client([
                'timeout' => 5,
                'connect_timeout' => 3,
            ]);

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

            return $result;

        } catch (GuzzleException $e) {
            throw new \RuntimeException('Ошибка связи с bot-t: ' . $e->getMessage());
        } catch (\Exception $e) {
            throw new \RuntimeException('Ошибка проверки пользователя: ' . $e->getMessage());
        }
    }

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
