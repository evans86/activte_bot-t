<?php

namespace App\Services\Activate;

use App\Dto\BotDto;
use App\Dto\BotFactory;
use App\Models\Activate\SmsCountry;
use App\Models\Bot\SmsBot;
use App\Models\Order\SmsOrder;
use App\Models\Rent\RentOrder;
use App\Models\User\SmsUser;
use App\Services\External\BottApi;
use App\Services\External\SmsActivateApi;
use App\Services\MainService;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use RuntimeException;
use Exception;

class RentService extends MainService
{
    /**
     * формируем список стран
     *
     * @param BotDto $botDto
     * @return array
     */
    public function getRentCountries(BotDto $botDto)
    {
        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);

        $resultRequest = \Cache::get('countries_rent');
        if ($resultRequest === null) {
            $resultRequest = $smsActivate->getRentServicesAndCountries();
            \Cache::put('countries_rent', $resultRequest, 900);
        }

        $countries = $resultRequest['countries'];

        $result = [];
        foreach ($countries as $country) {
            $smsCountry = SmsCountry::query()->where(['org_id' => $country])->first();

            array_push($result, [
                'id' => $smsCountry->org_id,
                'title_ru' => $smsCountry->name_ru,
                'title_eng' => $smsCountry->name_en,
                'image' => $smsCountry->image,
            ]);
        }

        return $result;
    }

    /**
     * формируем список сервисов
     *
     * @param BotDto $botDto
     * @param $country
     * @return array
     */
    public function getRentService(BotDto $botDto, $country)
    {
        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);

        $resultRequest = \Cache::get('services_rent_' . $country);
        if ($resultRequest === null) {
            $resultRequest = $smsActivate->getRentServicesAndCountries($country);
            \Cache::put('services_rent_' . $country, $resultRequest, 15);
        }
        $services = $resultRequest['services'];

        $result = [];

        if (!is_null($botDto->black))
            $black_array = explode(',', $botDto->black);

        foreach ($services as $key => $service) {

            if (!is_null($botDto->black)) {
                if (in_array($key, $black_array))
                    continue;
            }

            $amountStart = intval(floatval($service['retail_cost']) * 100);
            $amountFinal = $amountStart + ($amountStart * ($botDto->percent / 100));

            array_push($result, [
                'name' => $key,
                'count' => $service['quant']['total'],
                'cost' => $amountFinal,
                'image' => 'https://smsactivate.s3.eu-central-1.amazonaws.com/assets/ico/' . $key . '0.webp',
            ]);
        }

        return $result;
    }

    /**
     * получить цену аренды отдельного сервиса
     *
     * @param BotDto $botDto
     * @param $country
     * @param $service
     * @return mixed
     */
    public function getPriceService(BotDto $botDto, $country, $service, $time)
    {
        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);

        $resultRequest = $smsActivate->getRentServicesAndCountries($country, $time);

        if (!isset($resultRequest['services'][$service]))
            throw new RuntimeException('Сервис не указан или название неверно');

        $service = $resultRequest['services'][$service];
        $service_price = $service['retail_cost'];

        return $service_price;
    }

    /**
     * @param BotDto $botDto
     * @param $country
     * @param $service
     * @param $time
     * @return mixed
     */
    public function getTimePrice(BotDto $botDto, $country, $service, $time)
    {
        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);

        $resultRequest = $smsActivate->getRentServicesAndCountries($country, $time);

        if (!isset($resultRequest['services'][$service]))
            throw new RuntimeException('Сервис не указан или название неверно');

        $service = $resultRequest['services'][$service];
        $service_price = $service['retail_cost'];

        $amountStart = intval(floatval($service_price) * 100);
        $amountFinal = $amountStart + ($amountStart * ($botDto->percent / 100));

        return $amountFinal;
    }

    /**
     * создание заказа на аренду
     *
     * @param BotDto $botDto
     * @param $service
     * @param $country
     * @param $time
     * @param array|null $userData
     * @param $url
     * @return array
     */
    public function create(BotDto $botDto, $service, $country, $time, array $userData, $url = 'https://activate.bot-t.com/rent/updateSmsRent')
    {
        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);

        $user = SmsUser::query()->where(['telegram_id' => $userData['user']['telegram_id']])->first();
        if (is_null($user)) {
            throw new RuntimeException('not found user');
        }

        $country = SmsCountry::query()->where(['org_id' => $country])->first();
        $orderAmount = $this->getPriceService($botDto, $country->org_id, $service, $time);
        $amountStart = intval(floatval($orderAmount) * 100);
        $amountFinal = $amountStart + ($amountStart * ($botDto->percent / 100));

        //проверка баланса пользователя
        if ($amountFinal > $userData['money']) {
            throw new RuntimeException('Пополните баланс в боте..');
        }

        $resultRequest = $smsActivate->getRentNumber($service, $country->org_id, $time, $url);
        $end_time = strtotime($resultRequest['phone']['endDate']);

        // Попытаться списать баланс у пользователя
        $result = BottApi::subtractBalance($botDto, $userData, $amountFinal, 'Списание баланса для аренды номера.');

        // Неудача
        if (!$result['result']) {
            $result = $smsActivate->setRentStatus($resultRequest['phone']['id'], RentOrder::ACCESS_CANCEL);
            throw new RuntimeException('При списании баланса произошла ошибка: ' . $result['message']);
        }


        $data = [
            'bot_id' => $botDto->id,
            'user_id' => $user->id,
            'service' => $service,
            'country_id' => $country->id,
            'org_id' => $resultRequest['phone']['id'],
            'phone' => $resultRequest['phone']['number'],
            'codes' => null,
            'status' => RentOrder::STATUS_WAIT_CODE,
            'start_time' => time(),
            'end_time' => $end_time,
            'operator' => null,
            'price_final' => $amountFinal,
            'price_start' => $amountStart,
        ];

        $rent_order = RentOrder::create($data);

        $responseData = [
            'id' => $rent_order->org_id,
            'phone' => $rent_order->phone,
            'start_time' => $rent_order->start_time,
            'end_time' => $rent_order->end_time,
            'status' => $rent_order->status,
            'codes' => null,
            'country' => $country->org_id,
            'service' => $rent_order->service,
            'cost' => $amountFinal
        ];

        return $responseData;
    }

    /**
     * Отмена аренды
     *
     * @param BotDto $botDto
     * @param RentOrder|null $rent_order
     * @param array|null $userData
     * @return mixed
     */
    public function cancel(BotDto $botDto, RentOrder $rent_order, array $userData)
    {
        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);

        // Проверить уже отменёный
        if ($rent_order->status == RentOrder::STATUS_CANCEL)
            throw new RuntimeException('The order has already been canceled');
        // Проверить активированный
        if ($rent_order->status == RentOrder::STATUS_FINISH)
            throw new RuntimeException('The order has not been canceled, the number has been activated, Status 10');
        if (!is_null($rent_order->codes))
            throw new RuntimeException('The order has not been canceled, the number has been activated');

        $result = $smsActivate->setRentStatus($rent_order->org_id, RentOrder::ACCESS_CANCEL);

        $rent_order->status = RentOrder::STATUS_CANCEL;

        if ($rent_order->save()) {
            // Он же возвращает баланс
            $amountFinal = $rent_order->price_final;
            $result = BottApi::addBalance($botDto, $userData, $amountFinal, 'Возврат баланса, аренда отменена');
        } else {
            throw new RuntimeException('Not save order');
        }

        return $result;
    }

    /**
     * Успешно завершить аренду
     *
     * @param BotDto $botDto
     * @param RentOrder|null $rent_order
     * @param array|null $userData
     * @return false|mixed|string
     */
    public function confirm(BotDto $botDto, RentOrder $rent_order, array $userData)
    {
        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);

        if ($rent_order->status == RentOrder::STATUS_CANCEL)
            throw new RuntimeException('The order has already been canceled');
//        if (is_null($rent_order->codes))
//            throw new RuntimeException('Попытка установить несуществующий статус');
        if ($rent_order->status == RentOrder::STATUS_FINISH)
            throw new RuntimeException('The order has not been canceled, the number has been activated, Status 10');

        $result = $smsActivate->setRentStatus($rent_order->org_id, RentOrder::ACCESS_FINISH);

        $rent_order->status = RentOrder::STATUS_FINISH;

        if ($rent_order->save()) {
            BottApi::createOrder($botDto, $userData, $rent_order->price_final,
                'Заказ аренды для номера ' . $rent_order->phone);
        } else {
            throw new RuntimeException('Not save order');
        }

        return RentOrder::STATUS_FINISH;
    }

    /**
     * цена продления аренды
     *
     * @param BotDto $botDto
     * @param RentOrder|null $rent_order
     * @param $time
     * @return float|int
     */
    public function priceContinue(BotDto $botDto, RentOrder $rent_order, $time)
    {
        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);

        $resultRequest = $smsActivate->getContinueRentPriceNumber($rent_order->org_id, $time);
        $requestAmount = $resultRequest['price'];

        $amountStart = intval(floatval($requestAmount) * 100);
        $amountFinal = $amountStart + ($amountStart * ($botDto->percent / 100));

        return $amountFinal;
    }

    /**
     * продление срока аренды
     *
     * @param BotDto $botDto
     * @param RentOrder|null $rent_order
     * @param $time
     * @param array|null $userData
     * @return void
     */
    public function continueRent(BotDto $botDto, RentOrder $rent_order, $time, array $userData)
    {
        $smsActivate = new SmsActivateApi($botDto->api_key, $botDto->resource_link);

        $user = SmsUser::query()->where(['telegram_id' => $userData['user']['telegram_id']])->first();
        if (is_null($user)) {
            throw new RuntimeException('not found user');
        }

        $amountFinal = $this->priceContinue($botDto, $rent_order, $time);

        //проверка баланса пользователя
        if ($amountFinal > $userData['money']) {
            throw new RuntimeException('Пополните баланс в боте..');
        }

        // Попытаться списать баланс у пользователя
        $result = BottApi::subtractBalance($botDto, $userData, $amountFinal, 'Списание баланса для продления аренды номера.');

        // Неудача отмена - заказа
        if (!$result['result']) {
            throw new RuntimeException('При списании баланса произошла ошибка: ' . $result['message']);
        }

        $resultRequest = $smsActivate->continueRentNumber($rent_order->org_id, $time);

        $end_time = strtotime($resultRequest['phone']['endDate']);
        $rent_order->end_time = $end_time;

        $rent_order->save();
    }

    /**
     * обновление кода через вебхук
     *
     * @param array $hook_rent
     * @return void
     */
    public function updateSms(array $hook_rent)
    {
        $rent_org_id = $hook_rent['rentId'];
        $codes = $hook_rent['sms']['text'];
        $codes_date = strtotime($hook_rent['sms']['date']);
        $codes_id = $hook_rent['sms']['smsId'];

        $rentOrder = RentOrder::query()->where(['org_id' => $rent_org_id])->first();

        $new_codes = (string)$codes;

        $rentOrder->codes = $new_codes;

        $rentOrder->codes_id = $codes_id;
        $rentOrder->codes_date = $codes_date;

        $rentOrder->save();
    }

    /**
     * крон обновления статуса
     *
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function cronUpdateRentStatus(): void
    {
        try {
            $statuses = [RentOrder::STATUS_WAIT_CODE];

            $rent_orders = RentOrder::query()->whereIn('status', $statuses)
                ->where('end_time', '<=', time())->get();

            echo "START Rent count: " . count($rent_orders) . PHP_EOL;
            $start_text = "Activate Rent Start count: " . count($rent_orders) . PHP_EOL;
            $this->notifyTelegram($start_text);

            foreach ($rent_orders as $key => $rent_order) {
                echo $rent_order->id . PHP_EOL;

                $bot = SmsBot::query()->where(['id' => $rent_order->bot_id])->first();

                $botDto = BotFactory::fromEntity($bot);
                $result = BottApi::get(
                    $rent_order->user->telegram_id,
                    $botDto->public_key,
                    $botDto->private_key
                );

                echo 'confirm_start_rent' . PHP_EOL;
                $this->confirm(
                    $botDto,
                    $rent_order,
                    $result['data']
                );
                echo 'confirm_finish_rent' . PHP_EOL;

                echo "FINISH Rent " . $rent_order->id . PHP_EOL;
            }
            echo "FINISH count: " . count($rent_orders) . PHP_EOL;

            $finish_text = "Activate finish count: " . count($rent_orders) . PHP_EOL;
            $this->notifyTelegram($finish_text);

        } catch (Exception $e) {
            $this->notifyTelegram('🔴' . $e->getMessage());
        }
    }

    public function notifyTelegram($text)
    {
        $client = new Client();

        $client->post('https://api.telegram.org/bot6331654488:AAEmDoHZLV6D3YYShrwdanKlWCbo9nBjQy4/sendMessage', [

            RequestOptions::JSON => [
                'chat_id' => 398981226,
                'text' => $text,
            ]
        ]);
    }
}
