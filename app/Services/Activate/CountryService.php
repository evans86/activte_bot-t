<?php

namespace App\Services\Activate;

use App\Helpers\BotLogHelpers;
use App\Models\Activate\SmsCountry;
use App\Services\External\SmsActivateApi;
use App\Services\MainService;

class CountryService extends MainService
{
    /**
     * Получение, добавление стран и их операторов из API сервиса
     * @return void
     */
    public function getApiCountries()
    {
        //оставить свой API
        $smsActivate = new SmsActivateApi(config('services.key_activate.key'), BotService::DEFAULT_HOST);

        $countries = $smsActivate->getCountries();

        $this->formingCountriesArr($countries);
    }

    public function getCountries($bot)
    {
        $smsActivate = new SmsActivateApi($bot->api_key, $bot->resource_link);

        $countries = \Cache::get('countries_multi');
        if ($countries === null) {
            $countries = $smsActivate->getCountries();
            \Cache::put('countries_multi', $countries, 900);
        }

        $result = [];

        foreach ($countries as $key => $country) {

            array_push($result, [
                'org_id' => $country['id'],
                'name_ru' => $country['rus'],
                'name_en' => $country['eng'],
                'image' => 'https://smsactivate.s3.eu-central-1.amazonaws.com/assets/ico/country/' . $country['id'] . '.svg'
            ]);
        }

        return $result;
    }

    /**
     * Список стран по сервису
     *
     * @param $bot
     * @param $service
     * @return array
     */
    public function getPricesService($bot, $service = null)
    {
        $smsActivate = new SmsActivateApi($bot->api_key, $bot->resource_link);

        if ($bot->retail) {

            $countries = \Cache::get('countries_' . $service);
            if ($countries === null) {
                $countries = $smsActivate->getPrices(null, $service);
//                dd($countries);
                \Cache::put('countries_' . $service, $countries, 15);
            }

            return $this->formingRetailServices($countries, $service, $bot);
        } else {

            $countries = \Cache::get('countries_retail_' . $service);
            if ($countries === null) {
                $countries = $smsActivate->getTopCountriesByService($service);
//            dd($countries);
                \Cache::put('countries_retail_' . $service, $countries, 15);
            }
            return $this->formingServicesArr($countries, $bot);
        }
    }

    /**
     * @param $countries
     * @return void
     */
    private function formingCountriesArr($countries)
    {
        foreach ($countries as $key => $country) {

            $data = [
                'org_id' => $country['id'],
                'name_ru' => $country['rus'],
                'name_en' => $country['eng'],
                'image' => 'https://smsactivate.s3.eu-central-1.amazonaws.com/assets/ico/country/' . $country['id'] . '.svg'
            ];

            $country = SmsCountry::updateOrCreate($data);
            $country->save();
        }
    }

    /**
     * Формирование цены price из getPrices()
     *
     * @param $countries
     * @param $service
     * @param $bot
     * @return array
     */
    public function formingRetailServices($countries, $service, $bot)
    {
        $result = [];
//        dd($countries);
        foreach ($countries as $key => $country) {
            if (!array_key_exists($service, $country))
                continue;

//            dd($country);
            $smsCountry = SmsCountry::query()->where(['org_id' => $key])->first();
//            dd($smsCountry->org_id);

            $price = $country[$service]["cost"];
//            dd($price);

            $pricePercent = $price + ($price * ($bot->percent / 100));
//            dd($pricePercent);
            array_push($result, [
                'id' => $smsCountry->org_id,
                'title_ru' => $smsCountry->name_ru,
                'title_eng' => $smsCountry->name_en,
                'image' => $smsCountry->image,
                'count' => $country[$service]["count"],
                'cost' => $pricePercent * 100,
            ]);
        }

//        dd($result);

        return $result;
    }

    /**
     * Формирование списка стран с ценой для выбранного сервиса
     *
     * @param $countries
     * @param $bot
     * @return array
     */
    private function formingServicesArr($countries, $bot)
    {
        try {
            $result = [];
//        dd($bot);
            foreach ($countries as $key => $country) {

                $smsCountry = SmsCountry::query()->where(['org_id' => $country['country']])->first();
//            dd($smsCountry);
//            $country = current($country);
                $price = $country["retail_price"];

//            dd($price);

                $pricePercent = $price + ($price * ($bot->percent / 100));
//dd($pricePercent);
                array_push($result, [
                    'id' => $smsCountry->org_id,
                    'title_ru' => $smsCountry->name_ru,
                    'title_eng' => $smsCountry->name_en,
                    'image' => $smsCountry->image,
                    'count' => $country["count"],
                    'cost' => $pricePercent * 100,
                ]);
            }

//        dd($result);
            return $result;
        } catch (\Exception $e) {
//            dd($country);
            BotLogHelpers::notifyBotLog('(🔴E ' . __FUNCTION__ . ' Activate): ' . $country . $e->getMessage());
            return $result;
        }

    }
}
