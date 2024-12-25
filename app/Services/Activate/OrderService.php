<?php

namespace App\Services\Activate;

use App\Dto\BotDto;
use App\Dto\BotFactory;
use App\Models\Activate\SmsCountry;
use App\Models\Bot\SmsBot;
use App\Models\Order\SmsOrder;
use App\Models\User\SmsUser;
use App\Services\External\BottApi;
use App\Services\External\SmsActivateApi;
use App\Services\MainService;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use RuntimeException;
use Exception;

class OrderService extends MainService
{
    /**
     * @param BotDto $botDto
     * @param string $country_id
     * @param string $services
     * @param array $userData
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createMulti(BotDto $botDto, string $country_id, string $services, array $userData)
    {
        // Создать заказ по апи
        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);

        $user = SmsUser::query()->where(['telegram_id' => $userData['user']['telegram_id']])->first();
        if (is_null($user)) {
            throw new RuntimeException('not found user');
        }

        //Создание мультисервиса
        $serviceResults = $smsActivate->getMultiServiceNumber(
            $services,
            $forward = 0,
            $country_id,
        );

        //Получение активных активаций
        $activateActiveOrders = $smsActivate->getActiveActivations();
        $activateActiveOrders = $activateActiveOrders['activeActivations'];

        $orderAmount = 0;
        foreach ($activateActiveOrders as $activateActiveOrder) {
            $orderAmount += $activateActiveOrder['activationCost'];
        }

        //формирование общей цены заказа
        $amountFinal = intval(floatval($orderAmount) * 100);
        $amountFinal = $amountFinal + ($amountFinal * ($botDto->percent / 100));

        //отмена заказа если бабок недостаточно
        if ($amountFinal > $userData['money']) {
            foreach ($serviceResults as $key => $serviceResult) {
                $org_id = intval($serviceResult['activation']);
                $serviceResult = $smsActivate->setStatus($org_id, SmsOrder::ACCESS_CANCEL);
            }
            throw new RuntimeException('Пополните баланс в боте..');
        }

        // Попытаться списать баланс у пользователя
        $result = BottApi::subtractBalance($botDto, $userData, $amountFinal, 'Списание баланса для номера '
            . $serviceResults[0]['phone']);

        // Неудача отмена на сервисе
        if (!$result['result']) {
            foreach ($serviceResults as $key => $serviceResult) {
                $org_id = intval($serviceResult['activation']);
                $serviceResult = $smsActivate->setStatus($org_id, SmsOrder::ACCESS_CANCEL);
            }
            throw new RuntimeException('При списании баланса произошла ошибка: ' . $result['message']);
        }

        // Удача создание заказа в бд
        $country = SmsCountry::query()->where(['org_id' => $country_id])->first();
        $dateTime = intval(time());

        $response = [];

        foreach ($serviceResults as $key => $serviceResult) {
            $org_id = intval($serviceResult['activation']);
            foreach ($activateActiveOrders as $activateActiveOrder) {
                $active_org_id = intval($activateActiveOrder['activationId']);

                if ($org_id == $active_org_id) {
                    //формирование цены для каждого заказа
                    $amountStart = intval(floatval($activateActiveOrder['activationCost']) * 100);
                    $amountFinal = $amountStart + $amountStart * ($botDto->percent / 100);

                    $data = [
                        'bot_id' => $botDto->id,
                        'user_id' => $user->id, //
                        'service' => $activateActiveOrder['serviceCode'],
                        'country_id' => $country->id,
                        'org_id' => $activateActiveOrder['activationId'],
                        'phone' => $activateActiveOrder['phoneNumber'],
                        'codes' => null,
                        'status' => SmsOrder::STATUS_WAIT_CODE, //4
                        'start_time' => $dateTime,
                        'end_time' => $dateTime + 1177,
                        'operator' => null,
                        'price_final' => $amountFinal,
                        'price_start' => $amountStart,
                    ];

                    $order = SmsOrder::create($data);
                    $result = $smsActivate->setStatus($order, SmsOrder::ACCESS_RETRY_GET);
                    $result = $this->getStatus($order->org_id, $botDto);

                    array_push($response, [
                        'id' => $order->org_id,
                        'phone' => $order->phone,
                        'time' => $order->start_time,
                        'status' => $order->status,
                        'codes' => null,
                        'country' => $country->org_id,
                        'service' => $order->service,
                        'cost' => $amountFinal
                    ]);
                }
            }

        }

        return $response;
    }

    /**
     * Создание заказа
     *
     * @param array $userData Сущность DTO from bott
     * @param BotDto $botDto
     * @param string $country_id
     * @return array
     * @throws \Exception
     */
    public
    function create(array $userData, BotDto $botDto, string $country_id): array
    {
        // Создать заказ по апи
        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);
        $user = SmsUser::query()->where(['telegram_id' => $userData['user']['telegram_id']])->first();
        if (is_null($user)) {
            throw new RuntimeException('not found user');
        }
        if (empty($user->service))
            throw new RuntimeException('Choose service pls');

        $serviceResult = $smsActivate->getNumberV2(
            $user->service,
            $country_id
        );
        $org_id = intval($serviceResult['activationId']);
        // Из него получить цену
        $amountStart = intval(floatval($serviceResult['activationCost']) * 100);
        $amountFinal = $amountStart + $amountStart * $botDto->percent / 100;
        if ($amountFinal > $userData['money']) {
            $serviceResult = $smsActivate->setStatus($org_id, SmsOrder::ACCESS_CANCEL);
            throw new RuntimeException('Пополните баланс в боте');
        }
        // Попытаться списать баланс у пользователя
        $result = BottApi::subtractBalance($botDto, $userData, $amountFinal, 'Списание баланса для номера '
            . $serviceResult['phoneNumber']);

        // Неудача отмена на сервисе
        if (!$result['result']) {
            $serviceResult = $smsActivate->setStatus($org_id, SmsOrder::ACCESS_CANCEL);
            throw new RuntimeException('При списании баланса произошла ошибка: ' . $result['message']);
        }

        // Удача создание заказа в бд
        $country = SmsCountry::query()->where(['org_id' => $country_id])->first();
        $dateTime = new \DateTime($serviceResult['activationTime']);
        $dateTime = $dateTime->format('U');
        $dateTime = intval($dateTime);
        $data = [
            'bot_id' => $botDto->id,
            'user_id' => $user->id,
            'service' => $user->service,
            'country_id' => $country->id,
            'org_id' => $org_id,
            'phone' => $serviceResult['phoneNumber'],
            'codes' => null,
            'status' => SmsOrder::STATUS_WAIT_CODE, //4
            'start_time' => $dateTime,
            'end_time' => $dateTime + 1177,
            'operator' => $serviceResult['activationOperator'],
            'price_final' => $amountFinal,
            'price_start' => $amountStart,
        ];

        $order = SmsOrder::create($data);
        $result = $smsActivate->setStatus($order, SmsOrder::ACCESS_RETRY_GET);
        $result = $this->getStatus($order->org_id, $botDto);

        $result = [
            'id' => $order->org_id,
            'phone' => $serviceResult['phoneNumber'],
            'time' => $dateTime,
            'status' => $order->status,
            'codes' => null,
            'country' => $country->org_id,
            'operator' => $serviceResult['activationOperator'],
            'service' => $user->service,
            'cost' => $amountFinal
        ];
        return $result;
    }

    /**
     * Отмена заказа со статусом 9
     *
     * @param array $userData
     * @param BotDto $botDto
     * @param SmsOrder $order
     * @return mixed
     */
    public
    function cancel(array $userData, BotDto $botDto, SmsOrder $order)
    {
        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);

        // Проверить уже отменёный
//        if ($order->status == SmsOrder::STATUS_CANCEL)
//            throw new RuntimeException('The order has already been canceled');
//        if ($order->status == SmsOrder::STATUS_FINISH)
//            throw new RuntimeException('The order has not been canceled, the number has been activated, Status 10');
//        // Можно отменить только статус 4 и кодов нет
//        if (!is_null($order->codes))
//            throw new RuntimeException('The order has not been canceled, the number has been activated');

        // Обновить статус setStatus()
        $smsActivate->setStatus($order->org_id, SmsOrder::ACCESS_CANCEL);

        // Проверить статус getStatus()
//        $result = $this->getStatus($order->org_id, $botDto);
//        if ($result != SmsOrder::STATUS_CANCEL)
//            //надо писать лог
//            throw new RuntimeException('При проверке статуса произошла ошибка, вернулся статус: ' . $result);

//        $order->status = SmsOrder::STATUS_CANCEL;

        // Возврат баланаса если номер не использовали
        if (is_null($order->codes)) {
            $amountFinal = $order->price_final;
            $result = BottApi::addBalance($botDto, $userData, $amountFinal, 'Возврат баланса, активация отменена');
        } else {
            throw new RuntimeException('Not save order service');
        }
        return $result;
    }

    /**
     * @throws \Throwable
     */
    public function updateStatusCancel($order_id): void
    {
        \DB::transaction(function () use ($order_id) {
            $order = SmsOrder::lockForUpdate()->where(['org_id' => $order_id])->where(['status' => SmsOrder::STATUS_WAIT_CODE])->where(['status' => null])->first();
            $order->status = SmsOrder::STATUS_CANCEL;
            $order->save();
        });
    }

    /**
     * Успешное завершение заказа со статусом 10
     *
     * @param BotDto $botDto
     * @param SmsOrder $order
     * @return int
     */
    public
    function confirm(BotDto $botDto, SmsOrder $order)
    {
        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);

        if ($order->status == SmsOrder::STATUS_CANCEL)
            throw new RuntimeException('The order has already been canceled');
        if (is_null($order->codes))
            throw new RuntimeException('Попытка установить несуществующий статус');
        if ($order->status == SmsOrder::STATUS_FINISH)
            throw new RuntimeException('The order has not been canceled, the number has been activated, Status 10');

        $result = $smsActivate->setStatus($order->org_id, SmsOrder::ACCESS_ACTIVATION);

        $result = $this->getStatus($order->org_id, $botDto);

        $order->status = SmsOrder::STATUS_FINISH;

        $order->save();

        return SmsOrder::STATUS_FINISH;
    }

    /**
     * Повторное получение СМС
     *
     * @param BotDto $botDto
     * @param SmsOrder $order
     * @return int
     */
    public
    function second(BotDto $botDto, SmsOrder $order)
    {
        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);

        if ($order->status == SmsOrder::STATUS_CANCEL)
            throw new RuntimeException('The order has already been canceled');
        if (is_null($order->codes))
            throw new RuntimeException('Попытка установить несуществующий статус');
        if ($order->status == SmsOrder::STATUS_FINISH)
            throw new RuntimeException('The order has not been canceled, the number has been activated, Status 10');

        $result = $smsActivate->setStatus($order->org_id, SmsOrder::ACCESS_READY);

        $result = $this->getStatus($order->org_id, $botDto);

        if ($result != SmsOrder::STATUS_WAIT_RETRY)
            throw new RuntimeException('При проверке статуса произошла ошибка, вернулся статус: ' . $result);

        $resultSet = $order->status = SmsOrder::STATUS_WAIT_RETRY;

        $order->save();
        return $resultSet;
    }

    /**
     * Получение активного заказа и обновление кодов
     *
     * @param array $userData
     * @param BotDto $botDto
     * @param SmsOrder $order
     * @return void
     */
    public
    function order(array $userData, BotDto $botDto, SmsOrder $order): void
    {
        switch ($order->status) {
            case SmsOrder::STATUS_CANCEL:
            case SmsOrder::STATUS_FINISH:
                break;
            case SmsOrder::STATUS_WAIT_CODE:
            case SmsOrder::STATUS_WAIT_RETRY:
                $resultStatus = $this->getStatus($order->org_id, $botDto);
//                if ($resultStatus == null)
//                    $this->notifyTelegram('Что здесь пришло хз' . $resultStatus);
                switch ($resultStatus) {
                    case SmsOrder::STATUS_FINISH:
                    case SmsOrder::STATUS_CANCEL:
                        break;
                    case SmsOrder::STATUS_OK:
                    case SmsOrder::STATUS_WAIT_CODE:
                    case SmsOrder::STATUS_WAIT_RETRY:
                    case null:
                        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);
                        $activateActiveOrders = $smsActivate->getActiveActivations();
                        if (key_exists('activeActivations', $activateActiveOrders)) {
                            $activateActiveOrders = $activateActiveOrders['activeActivations'];

                            foreach ($activateActiveOrders as $activateActiveOrder) {
                                $order_id = $activateActiveOrder['activationId'];
                                // Есть ли совпадение
                                if ($order_id == $order->org_id) {
                                    // Есть ли смс
                                    $sms = $activateActiveOrder['smsCode'];
                                    if (is_null($sms))
                                        break;
                                    $sms = json_encode($sms);
                                    if (is_null($order->codes)) {
                                        BottApi::createOrder($botDto, $userData, $order->price_final,
                                            'Заказ активации для номера ' . $order->phone .
                                            ' с смс: ' . $sms);
                                    }
                                    $order->codes = $sms;
                                    $order->status = $resultStatus;
                                    $order->save();
                                    break;
                                }
                            }
                        }
                        break;
                    default:
                        throw new RuntimeException('Nеизвестный статус Вот этот: ' . $resultStatus . $order->id);
                }
        }
    }

    public function updateFlag(): void
    {
        $countries = SmsCountry::all();
        echo "START count:" . count($countries) . PHP_EOL;

        foreach ($countries as $key => $country) {
            $country->image = 'https://smsactivate.s3.eu-central-1.amazonaws.com/assets/ico/country/' . $country->org_id . '.svg';
            $country->save();
        }

        echo "FINISH count:" . count($countries) . PHP_EOL;
    }

    /**
     * Крон обновление статусов
     *
     * @return void
     */
    public
    function cronUpdateStatus(): void
    {
        try {
            $statuses = [SmsOrder::STATUS_OK, SmsOrder::STATUS_WAIT_CODE, SmsOrder::STATUS_WAIT_RETRY];

            $orders = SmsOrder::query()->whereIn('status', $statuses)
                ->where('end_time', '<=', time())->get();

            echo "START count:" . count($orders) . PHP_EOL;

            $start_text = "Activate Start count: " . count($orders) . PHP_EOL;
            $this->notifyTelegram($start_text);

            foreach ($orders as $key => $order) {
                echo $order->id . PHP_EOL;
                $bot = SmsBot::query()->where(['id' => $order->bot_id])->first();

                $botDto = BotFactory::fromEntity($bot);
                $result = BottApi::get(
                    $order->user->telegram_id,
                    $botDto->public_key,
                    $botDto->private_key
                );
                echo $order->id . PHP_EOL;

                if (is_null($order->codes)) {
                    echo 'cancel_start' . PHP_EOL;
                    $this->updateStatusCancel($order->org_id);
                    $this->cancel(
                        $result['data'],
                        $botDto,
                        $order
                    );
                    echo 'cancel_finish' . PHP_EOL;
                } else {
                    echo 'confirm_start' . PHP_EOL;
                    $this->order($result['data'], $botDto, $order);
                    $this->confirm(
                        $botDto,
                        $order
                    );
                    echo 'confirm_finish' . PHP_EOL;
                }
                echo "FINISH" . $order->id . PHP_EOL;

            }
            echo "FINISH count:" . count($orders) . PHP_EOL;

            $finish_text = "Activate finish count: " . count($orders) . PHP_EOL;
            $this->notifyTelegram($finish_text);

        } catch (Exception $e) {
            $this->notifyTelegram('🔴' . $e->getMessage());
        }
    }

    public function notifyTelegram($text)
    {
        $client = new Client();

        $ids = [
            6715142449,
//            778591134
        ];

        //CronLogBot#1
        try {
            foreach ($ids as $id) {
                $client->post('https://api.telegram.org/bot6393333114:AAHaxf8M8lRdGXqq6OYwly6rFQy9HwPeHaY/sendMessage', [

                    RequestOptions::JSON => [
                        'chat_id' => $id,
                        'text' => $text,
                    ]
                ]);
            }
            //CronLogBot#2
        } catch (\Exception $e) {
            foreach ($ids as $id) {
                $client->post('https://api.telegram.org/bot6934899828:AAGg_f4k1LG_gcZNsNF2LHgdm7tym-1sYVg/sendMessage', [

                    RequestOptions::JSON => [
                        'chat_id' => $id,
                        'text' => $text,
                    ]
                ]);
            }
        }
    }

    /**
     * Статус заказа с сервиса
     *
     * @param $id
     * @param BotDto $botDto
     * @return mixed
     */
    public
    function getStatus($id, BotDto $botDto)
    {
        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);

        return $smsActivate->getStatus($id);
    }
}
