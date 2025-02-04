<?php

namespace App\Services\Activate;

use App\Services\External\SmsActivateApi;
use App\Services\MainService;

class ProductService extends MainService
{
    /**
     * Все доступные сервисы с API
     *
     * @param $country
     * @return array
     */
    public function getAllProducts($country = null)
    {
        //оставить свой API
        $smsActivate = new SmsActivateApi(config('services.key_activate.key'), BotService::DEFAULT_HOST);

        return $smsActivate->getNumbersStatus($country);
    }

    public static function formingRublePrice(): float
    {
        $url = 'https://www.cbr.ru/scripts/XML_daily.asp';
        $xml = simplexml_load_file($url);
        $json = json_encode($xml);
        $currencies = json_decode($json, TRUE);
        $apiRate = '';
        foreach ($currencies['Valute'] as $key => $currency) {
            if ($currency['CharCode'] == 'USD')
                $apiRate = $currency['Value'];
        }
        $apiRate = str_replace(",", ".", $apiRate);
        return $apiRate;
    }

    /**
     * Сервисы доступные для конкретной страны
     *
     * @return array
     */
    public function getPricesCountry($bot)
    {
        $smsActivate = new SmsActivateApi($bot->api_key, $bot->resource_link);

//        if ($bot->resource_link == BotService::DEFAULT_HOST) {

            $services = \Cache::get('services_top_countries');
            if($services === null){
                $services = $smsActivate->getTopCountriesByService();
//                dd($services);
                \Cache::put('services_top_countries', $services, 900);
            }
            return $this->formingPricesArr($services, $bot);
//        } else {
//            $services = \Cache::get('services_price');
//            if($services === null){
//                $services = $smsActivate->getPrices();
//                \Cache::put('services_price', $services, 900);
//            }
//            return $this->formingPricesArr($services, $bot);
//        }
    }

    /**
     * @param $services
     * @return array
     */
    private function formingPricesArr($services, $bot)
    {
        $result = [];

        if (!is_null($bot->black))
            $black_array = explode(',', $bot->black);

//        dd($services);
        foreach ($services as $key => $service) {
//            dd($service);
            if (!is_null($bot->black)) {
                if (in_array($key, $black_array))
                    continue;
            }

            array_push($result, [
                'name' => $key,
                'image' => 'https://smsactivate.s3.eu-central-1.amazonaws.com/assets/ico/' . $key . '0.webp',
            ]);
        }

        return $result;
    }

    /**
     * Формирование списка сервисов с ценой для выбранной страны
     *
     * @param $bot
     * @param $country
     * @return array
     */
    public function getServices($bot, $country = null)
    {
        $apiRate = ProductService::formingRublePrice();
        $smsActivate = new SmsActivateApi($bot->api_key, $bot->resource_link);

        $services = \Cache::get('services_multi_' . $country);
        if($services === null){
            $services = $smsActivate->getPrices($country);
            \Cache::put('services_multi_' . $country, $services, 15);
        }
        $services = current($services);

        $result = [];

        if (!is_null($bot->black))
            $black_array = explode(',', $bot->black);

        foreach ($services as $key => $service) {

            if (!is_null($bot->black)) {
                if (in_array($key, $black_array))
                    continue;
            }

            $price = $service["cost"];
            $price = round(($apiRate * $price), 2);
            $pricePercent = $price + ($price * ($bot->percent / 100));

            array_push($result, [
                'name' => $key,
                'image' => 'https://smsactivate.s3.eu-central-1.amazonaws.com/assets/ico/' . $key . '0.webp',
                'count' => $service["count"],
                'cost' => $pricePercent * 100,
            ]);

        }

        return $result;
    }
}
