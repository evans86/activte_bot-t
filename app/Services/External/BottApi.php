<?php

namespace App\Services\External;

use App\Dto\BotDto;
use GuzzleHttp\Client;

class BottApi
{
    /**
     * Создание заказа в bot-t
     */
    public static function createOrder(BotDto $botDto, array $userData, int $amount, string $product)
    {
        try {
            $client = new Client(['timeout' => 10]);

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

            $result = json_decode($response->getBody()->getContents(), true);

            if (!$result['result']) {
                throw new \RuntimeException($result['message'] ?? 'Ошибка создания заказа');
            }

            return $result;

        } catch (\Exception $e) {
            throw new \RuntimeException('Ошибка связи с bot-t: ' . $e->getMessage());
        }
    }

    /**
     * Проверка пользователя
     */
    public static function checkUser(int $telegram_id, string $secret_key, string $public_key, string $private_key)
    {
        try {
            $client = new Client(['timeout' => 5]);

            $response = $client->get('https://api.bot-t.com/v1/module/user/check-secret?' . http_build_query([
                    'public_key' => $public_key,
                    'private_key' => $private_key,
                    'id' => $telegram_id,
                    'secret_key' => $secret_key,
                ]));

            return json_decode($response->getBody()->getContents(), true);

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
            $client = new Client(['timeout' => 10]);

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

            $result = json_decode($response->getBody()->getContents(), true);

            if (!$result['result']) {
                throw new \RuntimeException($result['message'] ?? 'Ошибка списания баланса');
            }

            return $result;

        } catch (\Exception $e) {
            throw new \RuntimeException('Ошибка списания баланса: ' . $e->getMessage());
        }
    }

    /**
     * Пополнение баланса
     */
    public static function addBalance(BotDto $botDto, array $userData, int $amount, string $comment)
    {
        try {
            $client = new Client(['timeout' => 10]);

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

            return json_decode($response->getBody()->getContents(), true);

        } catch (\Exception $e) {
            throw new \RuntimeException('Ошибка пополнения баланса: ' . $e->getMessage());
        }
    }
}
