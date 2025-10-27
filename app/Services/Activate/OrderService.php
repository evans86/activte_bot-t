<?php

namespace App\Services\Activate;

use App\Dto\BotDto;
use App\Dto\BotFactory;
use App\Helpers\BotLogHelpers;
use App\Helpers\OrdersHelper;
use App\Models\Activate\SmsCountry;
use App\Models\Bot\SmsBot;
use App\Models\Order\SmsOrder;
use App\Models\User\SmsUser;
use App\Services\External\BottApi;
use App\Services\External\SmsActivateApi;
use App\Services\MainService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\DB;
use Log;
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
        $apiRate = ProductService::formingRublePrice();
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
        $amountFinal = round(($apiRate * $amountFinal), 2);
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
                    $amountStart = round(($apiRate * $amountStart), 2);
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
                    Log::info('Activate: Произошло создание заказа (списание баланса) ' . $order->id);

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
        return \DB::transaction(function () use ($userData, $botDto, $country_id) {
            $apiRate = ProductService::formingRublePrice();
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
//        BotLogHelpers::notifyBotLog('🔴DEBUG ' . __FUNCTION__ . ' ActivationCOSTAPI: ' . $serviceResult['activationCost']);
            $amountStart = intval(floatval($serviceResult['activationCost']) * 100); //0.2 * 100 = 20
//        BotLogHelpers::notifyBotLog('🔴DEBUG ' . __FUNCTION__ . ' AmountStart 1: ' . $amountStart);
//        BotLogHelpers::notifyBotLog('🔴DEBUG ' . __FUNCTION__ . ' ApiRate: ' . $apiRate); // 80.4137

            $amountStart = round(($apiRate * $amountStart), 2); // 1608.27
//        BotLogHelpers::notifyBotLog('🔴DEBUG ' . __FUNCTION__ . ' AmountStart 2: ' . $amountStart);

            $amountFinal = $amountStart + $amountStart * $botDto->percent / 100;
//        BotLogHelpers::notifyBotLog('🔴DEBUG ' . __FUNCTION__ . ' AmountFinalllll: ' . $amountFinal);
//        BotLogHelpers::notifyBotLog('🔴DEBUG ' . __FUNCTION__ . ' userData: ' . $userData['money']);

//        '3296.9535'  '2000'

            if ($amountFinal > $userData['money']) {
                $serviceResult = $smsActivate->setStatus($org_id, SmsOrder::ACCESS_CANCEL);
//            BotLogHelpers::notifyBotLog('🔴DEBUG ' . __FUNCTION__ . ' AmountFinal: ' . $amountFinal);
                BotLogHelpers::notifyBotLog('🔴DEBUG ' . __FUNCTION__ . ' SERVICE RESULT: ' . $serviceResult);
                throw new RuntimeException('Пополните баланс в боте.');
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

            Log::info('Activate: Произошло создание заказа (списание баланса) ' . $order->id);

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
        });
    }

    /**
     * Создание заказа с транзакционностью и retry-логикой
     */
//    public function create(array $userData, BotDto $botDto, string $country_id): array
//    {
//        $maxRetries = 3;
//        $lastException = null;
//
//        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
//            try {
//                return DB::transaction(function () use ($userData, $botDto, $country_id, $attempt) {
//                    $apiRate = ProductService::formingRublePrice();
//                    $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);
//
//                    $user = SmsUser::where(['telegram_id' => $userData['user']['telegram_id']])->first();
//                    if (is_null($user)) {
//                        throw new RuntimeException('Пользователь не найден');
//                    }
//                    if (empty($user->service)) {
//                        throw new RuntimeException('Выберите сервис');
//                    }
//
//                    // 1. Сначала резервируем баланс в bot-t
//                    $serviceResult = $smsActivate->getNumberV2($user->service, $country_id);
//                    $org_id = intval($serviceResult['activationId']);
//
//                    $amountStart = intval(floatval($serviceResult['activationCost']) * 100);
//                    $amountStart = round(($apiRate * $amountStart), 2);
//                    $amountFinal = $amountStart + $amountStart * $botDto->percent / 100;
//
//                    if ($amountFinal > $userData['money']) {
//                        $smsActivate->setStatus($org_id, SmsOrder::ACCESS_CANCEL);
//                        throw new RuntimeException('Недостаточно средств. Пополните баланс в боте.');
//                    }
//
//                    // 2. Создаем заказ в bot-t (основная операция)
//                    $orderComment = 'Заказ активации для номера ' . $serviceResult['phoneNumber'] . ' (сервис: ' . $user->service . ')';
//                    $orderResult = $this->createOrderInBotWithRetry($botDto, $userData, $amountFinal, $orderComment);
//
//                    if (!$orderResult['result']) {
//                        $smsActivate->setStatus($org_id, SmsOrder::ACCESS_CANCEL);
//                        throw new RuntimeException('Ошибка создания заказа: ' . $orderResult['message']);
//                    }
//
//                    $orderIdInBot = $orderResult['data']['order_id'] ?? null;
//
//                    // 3. Сохраняем заказ в нашей БД с ID из bot-t
//                    $country = SmsCountry::where(['org_id' => $country_id])->first();
//                    $dateTime = new \DateTime($serviceResult['activationTime']);
//                    $dateTime = $dateTime->format('U');
//                    $dateTime = intval($dateTime);
//
//                    $data = [
//                        'bot_id' => $botDto->id,
//                        'user_id' => $user->id,
//                        'service' => $user->service,
//                        'country_id' => $country->id,
//                        'org_id' => $org_id,
//                        'bot_order_id' => $orderIdInBot, // Сохраняем ID заказа из bot-t
//                        'phone' => $serviceResult['phoneNumber'],
//                        'codes' => null,
//                        'status' => SmsOrder::STATUS_WAIT_CODE,
//                        'start_time' => $dateTime,
//                        'end_time' => $dateTime + 1177,
//                        'operator' => $serviceResult['activationOperator'],
//                        'price_final' => $amountFinal,
//                        'price_start' => $amountStart,
//                        'sync_status' => 'synced', // Статус синхронизации
//                    ];
//
//                    $order = SmsOrder::create($data);
//
//                    // 4. Подтверждаем статус у провайдера
//                    $smsActivate->setStatus($order->org_id, SmsOrder::ACCESS_RETRY_GET);
//                    $this->getStatus($order->org_id, $botDto);
//
//                    Log::info('Activate: Успешное создание заказа', [
//                        'order_id' => $order->id,
//                        'org_id' => $org_id,
//                        'bot_order_id' => $orderIdInBot,
//                        'attempt' => $attempt
//                    ]);
//
//                    return [
//                        'id' => $order->org_id,
//                        'phone' => $serviceResult['phoneNumber'],
//                        'time' => $dateTime,
//                        'status' => $order->status,
//                        'codes' => null,
//                        'country' => $country->org_id,
//                        'operator' => $serviceResult['activationOperator'],
//                        'service' => $user->service,
//                        'cost' => $amountFinal,
//                        'bot_order_id' => $orderIdInBot
//                    ];
//
//                }, 3); // 3 попытки для транзакции
//
//            } catch (Exception $e) {
//                $lastException = $e;
//                Log::warning("Попытка $attempt создания заказа не удалась", [
//                    'error' => $e->getMessage(),
//                    'user_id' => $userData['user']['telegram_id'] ?? null,
//                    'country_id' => $country_id
//                ]);
//
//                if ($attempt < $maxRetries) {
//                    sleep(1); // Ждем перед повторной попыткой
//                    continue;
//                }
//            }
//        }
//
//        // Если все попытки неудачны, логируем и бросаем исключение
//        BotLogHelpers::notifyBotLog("(🔴 CREATE_ORDER_FAILED): Все $maxRetries попыток создания заказа провалились: " . $lastException->getMessage());
//        throw new RuntimeException('Не удалось создать заказ. Попробуйте позже. ' . $lastException->getMessage());
//    }
//
//    /**
//     * Создание заказа в bot-t с повторными попытками
//     */
//    private function createOrderInBotWithRetry(BotDto $botDto, array $userData, int $amount, string $product)
//    {
//        $maxRetries = 3;
//        $lastException = null;
//
//        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
//            try {
//                $result = BottApi::createOrder($botDto, $userData, $amount, $product);
//
//                if ($result['result']) {
//                    Log::info("Заказ успешно создан в bot-t", [
//                        'attempt' => $attempt,
//                        'amount' => $amount,
//                        'user_id' => $userData['user']['telegram_id']
//                    ]);
//                    return $result;
//                }
//
//                // Если результат false, но нет исключения
//                throw new RuntimeException($result['message'] ?? 'Unknown error from bot-t');
//
//            } catch (Exception $e) {
//                $lastException = $e;
//                Log::warning("Попытка $attempt создания заказа в bot-t не удалась", [
//                    'error' => $e->getMessage(),
//                    'attempt' => $attempt
//                ]);
//
//                if ($attempt < $maxRetries) {
//                    sleep(1);
//                    continue;
//                }
//            }
//        }
//
//        throw new RuntimeException("Не удалось создать заказ в системе после $maxRetries попыток: " . $lastException->getMessage());
//    }

    /**
     * Отмена заказа со статусом 9
     *
     * @param array $userData
     * @param BotDto $botDto
     * @param SmsOrder $order
     * @return mixed
     * @throws GuzzleException
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
            BotLogHelpers::notifyBotLog('(🔴SUB ' . __FUNCTION__ . ' Activate): ' . 'Вернул баланс order_id = ' . $order->id);
            $result = BottApi::addBalance($botDto, $userData, $amountFinal, 'Возврат баланса, активация отменена order_id: ' . $order->id);
            Log::info('Activate: Произошла отмена заказа (возврат баланса) ' . $order->id);
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
            $order = SmsOrder::lockForUpdate()->where(['org_id' => $order_id])->where(['status' => SmsOrder::STATUS_WAIT_CODE])->first();
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
            throw new RuntimeException('The order has not been canceled, the number has been activated, Status 10 ' . $order->id);

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
            throw new RuntimeException('The order has not been canceled, the number has been activated, Status 10 ' . $order->id);

        $result = $smsActivate->setStatus($order->org_id, SmsOrder::ACCESS_READY);

        $result = $this->getStatus($order->org_id, $botDto);

        if ($result != SmsOrder::STATUS_WAIT_RETRY)
            throw new RuntimeException('При проверке статуса произошла ошибка, вернулся статус: ' . $result);

        $resultSet = $order->status = SmsOrder::STATUS_WAIT_RETRY;

        $order->save();
        return $resultSet;
    }

//    /**
//     * УПРОЩЕННАЯ ЛОГИКА ПОЛУЧЕНИЯ ЗАКАЗА
//     * Только базовые проверки и получение SMS
//     */
//    public function order(array $userData, BotDto $botDto, SmsOrder $order): void
//    {
//        // Если заказ уже обработан - выходим
//        if ($order->is_created) {
//            return;
//        }
//
//        // Пробуем получить статус от провайдера
//        try {
//            $providerStatus = $this->getStatus($order->org_id, $botDto);
//
//            // Если статус OK - значит есть SMS
//            if ($providerStatus === SmsOrder::STATUS_OK) {
//                $this->getAndProcessSms($botDto, $userData, $order);
//            } else {
//                // Просто обновляем статус
//                $order->status = $providerStatus;
//                $order->save();
//            }
//
//        } catch (\Exception $e) {
//            \Log::error('Error getting order status', [
//                'order_id' => $order->id,
//                'error' => $e->getMessage()
//            ]);
//        }
//    }
//
//    /**
//     * ПРОСТАЯ ЛОГИКА ПОЛУЧЕНИЯ И ОБРАБОТКИ SMS
//     */
//    private function getAndProcessSms(BotDto $botDto, array $userData, SmsOrder $order): void
//    {
//        $smsCode = $this->getSmsCodeFromProvider($botDto, $order);
//
//        if (empty($smsCode)) {
//            \Log::info('No SMS code yet', ['order_id' => $order->id]);
//            return;
//        }
//
//        \Log::info('SMS code received', [
//            'order_id' => $order->id,
//            'sms_code' => $smsCode
//        ]);
//
//        // СОЗДАЕМ УВЕДОМЛЕНИЕ И СОХРАНЯЕМ КОД
//        $this->createSingleNotification($botDto, $userData, $order, $smsCode);
//    }
//
//    /**
//     * ПРОСТОЙ МЕТОД ПОЛУЧЕНИЯ SMS КОДА
//     */
//    private function getSmsCodeFromProvider(BotDto $botDto, SmsOrder $order): ?string
//    {
//        try {
//            $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);
//            $statusResult = $smsActivate->getStatus($order->org_id);
//
//            // Если статус напрямую содержит код
//            if (is_string($statusResult) && strlen($statusResult) >= 4 && is_numeric($statusResult)) {
//                return $statusResult;
//            }
//
//            // Или получаем из активных активаций
//            $activeData = $smsActivate->getActiveActivations();
//
//            if (isset($activeData['activeActivations']) && is_array($activeData['activeActivations'])) {
//                foreach ($activeData['activeActivations'] as $activation) {
//                    if (($activation['activationId'] ?? null) == $order->org_id) {
//                        return $activation['smsCode'] ?? $activation['smsText'] ?? null;
//                    }
//                }
//            }
//
//            return null;
//
//        } catch (\Exception $e) {
//            \Log::error('Error getting SMS from provider', [
//                'order_id' => $order->id,
//                'error' => $e->getMessage()
//            ]);
//            return null;
//        }
//    }
//
//    /**
//     * СОЗДАНИЕ УВЕДОМЛЕНИЯ (ГАРАНТИРОВАННО ОДИН РАЗ)
//     */
//    private function createSingleNotification(BotDto $botDto, array $userData, SmsOrder $order, string $smsCode): void
//    {
//        // Используем простую блокировку через базу
//        DB::transaction(function () use ($botDto, $userData, $order, $smsCode) {
//            // Повторно проверяем с блокировкой
//            $freshOrder = SmsOrder::where('id', $order->id)->lockForUpdate()->first();
//
//            if (!$freshOrder || $freshOrder->is_created) {
//                return; // Уже обработан
//            }
//
//            $smsJson = json_encode([$smsCode]);
//
//            try {
//                // СОЗДАЕМ УВЕДОМЛЕНИЕ В BOT-T
//                $result = BottApi::createOrder(
//                    $botDto,
//                    $userData,
//                    $freshOrder->price_final,
//                    "SMS: {$freshOrder->phone} - {$smsCode}"
//                );
//
//                if ($result && ($result['result'] ?? false)) {
//                    // СОХРАНЯЕМ КОД И ФЛАГ
//                    $freshOrder->codes = $smsJson;
//                    $freshOrder->is_created = true;
//                    $freshOrder->status = SmsOrder::STATUS_OK;
//                    $freshOrder->save();
//
//                    \Log::info('✅ SMS notification created', [
//                        'order_id' => $freshOrder->id,
//                        'phone' => $freshOrder->phone,
//                        'sms_code' => $smsCode
//                    ]);
//
//                    // Уведомление в телеграм
//                    BotLogHelpers::notifyBotLog("✅ SMS: {$freshOrder->phone} - {$smsCode}");
//
//                } else {
//                    \Log::error('❌ Failed to create notification', [
//                        'order_id' => $freshOrder->id,
//                        'response' => $result
//                    ]);
//                }
//
//            } catch (\Exception $e) {
//                \Log::error('❌ Exception creating notification', [
//                    'order_id' => $freshOrder->id,
//                    'error' => $e->getMessage()
//                ]);
//            }
//        });
//    }

//    /**
//     * ПРЯМОЕ ПОЛУЧЕНИЕ СТАТУСА
//     */
//    public function getStatus($id, BotDto $botDto)
//    {
//        try {
//            $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);
//            return $smsActivate->getStatus($id);
//        } catch (\Exception $e) {
//            \Log::error('Error in getStatus', [
//                'activation_id' => $id,
//                'error' => $e->getMessage()
//            ]);
//            return 'ERROR';
//        }
//    }

//    /**
//     * @param array $userData
//     * @param BotDto $botDto
//     * @param SmsOrder $order
//     * @return void
//     */
//    public function order(array $userData, BotDto $botDto, SmsOrder $order): void
//    {
//        // Добавляем проверку на null заказ
//        if (!$order) {
//            throw new RuntimeException('Order is null');
//        }
//
//        switch ($order->status) {
//            case SmsOrder::STATUS_CANCEL:
//            case SmsOrder::STATUS_FINISH:
//                break;
//            case SmsOrder::STATUS_WAIT_CODE:
//            case SmsOrder::STATUS_WAIT_RETRY:
//                $resultStatus = $this->getStatus($order->org_id, $botDto);
//
//                switch ($resultStatus) {
//                    case OrdersHelper::requestArray('BAD_KEY'):
//                    case OrdersHelper::requestArray('WRONG_ACTIVATION_ID'):
//                        $this->notifyTelegram('BAD_KEY ' . $order->id);
//
//                        $isCodesEmpty = empty($order->codes) ||
//                            $order->codes === '[]' ||
//                            $order->codes === '[ ]' ||
//                            $order->codes === '""' ||
//                            $order->codes === '';
//
//                        if ($isCodesEmpty) {
//                            $order->status = SmsOrder::STATUS_CANCEL;
//                        } else {
//                            $order->status = SmsOrder::STATUS_FINISH;
//                        }
//                        $order->save();
//                        break;
//
//                    case SmsOrder::STATUS_FINISH:
//                    case SmsOrder::STATUS_CANCEL:
//                        break;
//
//                    case SmsOrder::STATUS_OK:
//                    case SmsOrder::STATUS_WAIT_CODE:
//                    case SmsOrder::STATUS_WAIT_RETRY:
//                        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);
//                        $activateActiveOrders = $smsActivate->getActiveActivations();
//
//                        if (isset($activateActiveOrders['activeActivations'])) {
//                            $activateActiveOrders = $activateActiveOrders['activeActivations'];
//
//                            foreach ($activateActiveOrders as $activateActiveOrder) {
//                                $order_id = $activateActiveOrder['activationId'] ?? null;
//
//                                if (!$order_id || $order_id != $order->org_id) {
//                                    continue;
//                                }
//
//                                $sms = $activateActiveOrder['smsCode'] ?? $activateActiveOrder['smsText'] ?? null;
//
//                                // Улучшенная проверка на пустое SMS
//                                $isSmsEmpty = empty($sms) ||
//                                    $sms === '[]' ||
//                                    $sms === '[ ]' ||
//                                    $sms === '""' ||
//                                    (is_array($sms) && empty($sms));
//
//                                if ($isSmsEmpty) {
//                                    break;
//                                }
//
//                                $smsJson = json_encode([$sms]);
//
//                                // УСИЛЕННАЯ ПРОВЕРКА ДЛЯ ПРЕДОТВРАЩЕНИЯ ПОВТОРОВ
//                                if (!empty($order->codes) &&
//                                    $order->is_created == false &&
//                                    !empty($sms) &&
//                                    $sms !== $order->codes) {
//
//                                    // Используем транзакцию для атомарности
//                                    DB::transaction(function () use ($botDto, $userData, $order, $smsJson) {
//                                        $result = BottApi::createOrder(
//                                            $botDto,
//                                            $userData,
//                                            $order->price_final,
//                                            'Заказ активации для номера ' . $order->phone . ' с смс: ' . $smsJson
//                                        );
//
//                                        if ($result['result']) {
//                                            $order->is_created = true;
//                                            $order->codes = $smsJson;
//                                            $order->status = SmsOrder::STATUS_OK;
//                                            $order->save();
//                                        }
//                                    });
//                                } elseif (empty($order->codes) && !empty($sms)) {
//                                    // Только обновляем коды без создания заказа
//                                    $order->codes = $smsJson;
//                                    $order->status = $resultStatus;
//                                    $order->save();
//                                }
//                                break;
//                            }
//                        }
//                        break;
//                    default:
//                        throw new RuntimeException('Неизвестный статус: ' . $order->id);
//                }
//        }
//    }

    /**
     * Получение активного заказа и обновление кодов
     * ИСПРАВЛЕННАЯ ВЕРСИЯ - правильный формат кода и одно уведомление
     */
    public function order(array $userData, BotDto $botDto, SmsOrder $order): void
    {
        switch ($order->status) {
            case SmsOrder::STATUS_CANCEL:
            case SmsOrder::STATUS_FINISH:
                break;
            case SmsOrder::STATUS_WAIT_CODE:
            case SmsOrder::STATUS_WAIT_RETRY:
                $resultStatus = $this->getStatus($order->org_id, $botDto);
                switch ($resultStatus) {
                    case OrdersHelper::requestArray('BAD_KEY'):
                    case OrdersHelper::requestArray('WRONG_ACTIVATION_ID'):
                        $this->notifyTelegram('BAD_KEY' . $order->id);
                        if (is_null($order->codes) || $order->codes == '[]' || $order->codes == '[ ]') {
                            $order->status = SmsOrder::STATUS_CANCEL;
                        } else {
                            $order->status = SmsOrder::STATUS_FINISH;
                        }
                        $order->save();
                        break;
                    case SmsOrder::STATUS_FINISH:
                    case SmsOrder::STATUS_CANCEL:
                        break;
                    case SmsOrder::STATUS_OK:
                    case SmsOrder::STATUS_WAIT_CODE:
                    case SmsOrder::STATUS_WAIT_RETRY:
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

                                    if (is_null($sms) || $sms == '[]') {
                                        $sms = $activateActiveOrder['smsText'];
                                    }

                                    // Проверяем что SMS не пустое и валидное
                                    if (is_null($sms) || $sms == '[]' || empty(trim($sms))) {
                                        break;
                                    }

                                    // ФОРМАТИРУЕМ КОД ПРАВИЛЬНО: ["111111"]
                                    $smsJson = json_encode([trim($sms)]);

                                    // ВАЖНО: сохраняем код СРАЗУ при получении
                                    $order->codes = $smsJson;
                                    $order->status = $resultStatus;

                                    // СОЗДАЕМ УВЕДОМЛЕНИЕ ТОЛЬКО ЕСЛИ ЕЩЕ НЕ СОЗДАВАЛИ
                                    if ($order->is_created == false) {
                                        try {
                                            BottApi::createOrder($botDto, $userData, $order->price_final,
                                                'Заказ активации для номера ' . $order->phone .
                                                ' с смс: ' . $smsJson);
                                            $order->is_created = true;
                                            \Log::info('SMS notification created', [
                                                'order_id' => $order->id,
                                                'phone' => $order->phone,
                                                'sms' => $sms
                                            ]);
                                        } catch (\Exception $e) {
                                            \Log::error('Error creating notification', [
                                                'order_id' => $order->id,
                                                'error' => $e->getMessage()
                                            ]);
                                            // НЕ сбрасываем флаг is_created при ошибке - пробуем снова
                                        }
                                    }

                                    $order->save();
                                    break;
                                }
                            }
                        }
                        break;
                    default:
                        throw new RuntimeException('Неизвестный статус: ' . $order->id);
                }
        }
    }

//    /**
//     * Получение активного заказа и обновление кодов
//     *
//     * @param array $userData
//     * @param BotDto $botDto
//     * @param SmsOrder $order
//     * @return void
//     */
//    public
//    function order(array $userData, BotDto $botDto, SmsOrder $order): void
//    {
//        switch ($order->status) {
//            case SmsOrder::STATUS_CANCEL:
//            case SmsOrder::STATUS_FINISH:
//                break;
//            case SmsOrder::STATUS_WAIT_CODE:
//            case SmsOrder::STATUS_WAIT_RETRY:
//                $resultStatus = $this->getStatus($order->org_id, $botDto);
//                switch ($resultStatus) {
////                    case null:
////                        throw new RuntimeException('ЭТО NULL');
//                    case OrdersHelper::requestArray('BAD_KEY'):
//                    case OrdersHelper::requestArray('WRONG_ACTIVATION_ID'):
//                        $this->notifyTelegram('BAD_KEY' . $order->id);
//                        if (is_null($order->codes) || $order->codes == '[]' || $order->codes == '[ ]') {
//                            $order->status = SmsOrder::STATUS_CANCEL;
//                        } else {
//                            $order->status = SmsOrder::STATUS_FINISH;
//                        }
//                        $order->save();
//                        break;
//                    case SmsOrder::STATUS_FINISH:
//                    case SmsOrder::STATUS_CANCEL:
//                        break;
//                    case SmsOrder::STATUS_OK:
//                    case SmsOrder::STATUS_WAIT_CODE:
//                    case SmsOrder::STATUS_WAIT_RETRY:
//                        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);
//                        $activateActiveOrders = $smsActivate->getActiveActivations();
//                        if (key_exists('activeActivations', $activateActiveOrders)) {
//                            $activateActiveOrders = $activateActiveOrders['activeActivations'];
//
//                            foreach ($activateActiveOrders as $activateActiveOrder) {
//                                $order_id = $activateActiveOrder['activationId'];
//                                // Есть ли совпадение
//                                if ($order_id == $order->org_id) {
//                                    // Есть ли смс
//                                    $sms = $activateActiveOrder['smsCode'];
//
//
//                                    if (is_null($sms) || $sms == '[]') {
//                                        $sms = $activateActiveOrder['smsText'];
//                                    }
//
//                                    if (is_null($sms) || $sms == '[]') {
//                                        break;
//                                    }
//
//                                    $sms = json_encode($sms);
//
//                                    if (!is_null($order->codes) && $order->is_created == false) {
//                                        BottApi::createOrder($botDto, $userData, $order->price_final,
//                                            'Заказ активации для номера ' . $order->phone .
//                                            ' с смс: ' . $sms);
//                                        $order->is_created = true;
//                                        $order->save();
//                                    }
//                                    $order->codes = $sms;
//                                    $order->status = $resultStatus;
//                                    $order->save();
//                                    break;
//                                }
//                            }
//                        }
//                        break;
//                    default:
//                        throw new RuntimeException('Неизвестный статус: ' . $order->id);
//                }
//        }
//    }

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

            $orders = SmsOrder::query()
                ->whereIn('status', $statuses)
                ->where('end_time', '<=', time())
                ->where('status', '!=', SmsOrder::STATUS_CANCEL) // Исключаем уже отмененные заказы
                ->lockForUpdate()
                ->get();

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

//    public function notifyTelegram($text)
//    {
//        $client = new Client();
//
//        $ids = [
//            6715142449,
////            778591134
//        ];
//
//        //CronLogBot#1
//        try {
//            foreach ($ids as $id) {
//                $client->post('https://api.telegram.org/bot6393333114:AAHaxf8M8lRdGXqq6OYwly6rFQy9HwPeHaY/sendMessage', [
//
//                    RequestOptions::JSON => [
//                        'chat_id' => $id,
//                        'text' => $text,
//                    ]
//                ]);
//            }
//            //CronLogBot#2
//        } catch (\Exception $e) {
//            foreach ($ids as $id) {
//                $client->post('https://api.telegram.org/bot6934899828:AAGg_f4k1LG_gcZNsNF2LHgdm7tym-1sYVg/sendMessage', [
//
//                    RequestOptions::JSON => [
//                        'chat_id' => $id,
//                        'text' => $text,
//                    ]
//                ]);
//            }
//        }
//    }

    public function notifyTelegram($text)
    {
        $client = new Client([
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // Принудительно IPv4
            ],
            'timeout' => 10,
            'connect_timeout' => 5,
        ]);

        $ids = [6715142449]; // Список chat_id
        $bots = [
            config('services.bot_api_keys.cron_log_bot_1'), // Основной бот
            config('services.bot_api_keys.cron_log_bot_2')  // Резервный бот
        ];

        // Если текст пустой, заменяем его на заглушку (или оставляем пустым)
        $message = ($text === '') ? '[Empty message]' : $text;

        $lastError = null;

        foreach ($bots as $botToken) {
            try {
                foreach ($ids as $id) {
                    $client->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                        RequestOptions::JSON => [
                            'chat_id' => $id,
                            'text' => $message,
                        ],
                    ]);
                }
                return true; // Успешно отправлено
            } catch (\Exception $e) {
                $lastError = $e;
                continue; // Пробуем следующего бота
            }
        }

        // Если все боты не сработали, логируем ошибку (или просто игнорируем)
        error_log("Telegram send failed: " . $lastError->getMessage());
        return false;
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

        $serviceResult = $smsActivate->getStatus($id);
        return $serviceResult;
    }
}
