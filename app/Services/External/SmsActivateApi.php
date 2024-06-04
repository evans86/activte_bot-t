<?php

namespace App\Services\External;

use App\Helpers\OrdersHelper;
use GuzzleHttp\Client;
use http\Exception\InvalidArgumentException;

class SmsActivateApi
{
//    private $url = 'https://api.sms-activate.org/stubs/handler_api.php';
    private $url;

    private $apiKey;

    public function __construct($apiKey, $url)
    {
        $this->apiKey = $apiKey;
        $this->url = $url;
    }

    public function getBalance()
    {
        return $this->request(array('api_key' => $this->apiKey, 'action' => __FUNCTION__), 'GET', true, 3);
    }

    public function getBalanceAndCashBack()
    {
        return $this->request(array('api_key' => $this->apiKey, 'action' => __FUNCTION__), 'GET');
    }

    public function getTopCountriesByService($service = null, $freePrice = false)
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'service' => $service, '$freePrice' => $freePrice);
        return $this->request($requestParam, 'POST', true);
    }

    public function getNumbersStatus($country = null, $operator = null)
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__);
        if ($country) {
            $requestParam['country'] = $country;
        }
        if ($operator && ($country == 0 || $country == 1 || $country == 2)) {
            $requestParam['service'] = $operator;
        }
        $response = array();
        $changeKeys = $this->request($requestParam, 'GET', true);
//        $changeKeys = json_decode($changeKeys, true);
        foreach ($changeKeys as $services => $count) {
            $services = trim($services, "_01");
            $response[$services] = $count;
        }
        unset($changeKeys);
        return $response;
    }

    public function getNumber($service, $country = null, $forward = 0, $operator = null, $ref = null)
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'service' => $service, 'forward' => $forward);
        if ($country) {
            $requestParam['country'] = $country;
        }
        if ($operator && ($country == 0 || $country == 1 || $country == 2)) {
            $requestParam['operator'] = $operator;
        }
        if ($ref) {
            $requestParam['ref'] = $ref;
        }
        return $this->request($requestParam, 'POST', null);
    }

    public function getNumberV2($service, $country = null, $forward = 0, $operator = null)
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'service' => $service, 'forward' => $forward, 'ref' => 'WEB');
        if (!is_null($country)) {
            $requestParam['country'] = $country;
        }
        if ($operator && ($country == 0 || $country == 1 || $country == 2)) {
            $requestParam['operator'] = $operator;
        }
        return $this->request($requestParam, 'POST', null);
    }

    public function getMultiServiceNumber($services, $forward = 0, $country = null, $operator = null)
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'multiService' => $services, 'forward' => $forward, 'ref' => 'WEB');
        if ($country) {
            $requestParam['country'] = $country;
        }
        if ($operator && ($country == 0 || $country == 1 || $country == 2)) {
            $requestParam['operator'] = $operator;
        }
        return $this->request($requestParam, 'POST', true, 3);
    }

    public function setStatus($id, $status, $forward = 0)
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'id' => $id, 'status' => $status);

        if ($forward) {
            $requestParam['forward'] = $forward;
        }

        return $this->request($requestParam, 'POST', null, 1);
    }

    public function getStatus($id)
    {
        return $this->request(array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'id' => $id), 'GET', false, 1);
    }

    public function getCountries()
    {
        return $this->request(array('api_key' => $this->apiKey, 'action' => __FUNCTION__), 'GET', true);
    }

    public function getActiveActivations()
    {
        return $this->request(array('api_key' => $this->apiKey, 'action' => __FUNCTION__), 'GET', true);
    }

    public function getAdditionalService($service, $activationId)
    {
        return $this->request(array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'service' => $service, 'id' => $activationId), 'GET', false, 1);
    }

    public function getFullSms($id)
    {
        return $this->request(array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'id' => $id), 'GET');
    }

    public function getPrices($country = null, $service = null)
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__);

        if ($country !== null) {
            $requestParam['country'] = $country;
        }
        if ($service) {
            $requestParam['service'] = $service;
        }

        return $this->request($requestParam, 'GET', true);
    }

    public function getRentServicesAndCountries($country = "0", $time = 4, $operator = "any")
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'rent_time' => $time, 'operator' => $operator, 'country' => $country);
        return $this->requestRent($requestParam, 'POST', false, 1);
    }

    public function getOperators($country)
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'country' => $country);
        return $this->requestRent($requestParam, 'POST', true);
    }

    public function getPricesActivation($service)
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'service' => $service);
        return $this->requestRent($requestParam, 'POST', true);
    }

    public function getPricesVerification($service)
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'service' => $service);
        return $this->requestRent($requestParam, 'POST', true);
    }

    public function getRentNumber($service, $country = 0, $time = 4, $url = '', $operator = "any")
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'service' => $service, 'rent_time' => $time, 'operator' => $operator, 'country' => $country, 'url' => $url);
        return $this->requestRent($requestParam, 'POST', true,);
    }

    public function getRentStatus($id)
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'id' => $id);
        return $this->requestRent($requestParam, 'POST', true);
    }

    public function setRentStatus($id, $status)
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'id' => $id, 'status' => $status);
        return $this->requestRent($requestParam, 'POST', true);
    }

    public function getRentList()
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__);
        return $this->requestRent($requestParam, 'POST', true);
    }

    public function continueRentNumber($id, $time = 4)
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'id' => $id, 'rent_time' => $time);
        return $this->requestRent($requestParam, 'POST', true);
    }

    public function getContinueRentPriceNumber($id, $time)
    {
        $requestParam = array('api_key' => $this->apiKey, 'action' => __FUNCTION__, 'id' => $id, 'rent_time' => $time);
        return $this->requestRent($requestParam, 'POST', true, 3);
    }

    /**
     * @param $data
     * @param $method
     * @param null $parseAsJSON
     * @return mixed
     */
    private function request($data, $method, $parseAsJSON = null, $getNumber = null)
    {
        $method = strtoupper($method);

        if (!in_array($method, array('GET', 'POST'))) {
            throw new InvalidArgumentException('Method can only be GET or POST');
        }

        $serializedData = http_build_query($data);
//        dd("$this->url?$serializedData");

//        dd($serializedData);
        $serializedData = str_replace('&amp;', '&', $serializedData);
//
//        $context = stream_context_create(array(
//            'http' => array(
//                'header' => array('User-Agent: Mozilla/5.0 (Windows; U; Windows NT 6.1; rv:2.2) Gecko/20110201'),
//            ),
//        ));

        if ($method === 'GET') {
//            $context = stream_context_create(
//                array(
//                    "http" => array(
//                        "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; WOW64)
//                            AppleWebKit/537.36 (KHTML, like Gecko)
//                            Chrome/50.0.2661.102 Safari/537.36\r\n" .
//                            "accept: text/html,application/xhtml+xml,application/xml;q=0.9,
//                            image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3\r\n" .
//                            "accept-language: es-ES,es;q=0.9,en;q=0.8,it;q=0.7\r\n" .
//                            "accept-encoding: gzip, deflate, br\r\n"
//                    )
//                )
//            );
//            $url = "$this->url?$serializedData";
//            $ch = curl_init();
//            curl_setopt($ch, CURLOPT_URL, $url);
//            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
//            curl_setopt($ch,CURLOPT_USERAGENT,'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');
//            $result = curl_exec($ch);
//            curl_close($ch);

//            $client = new Client(['base_uri' => $this->url]);
//            $response = $client->get($this->url . '?' . $serializedData);
//            $result = $response->getBody()->getContents();

            $result = file_get_contents("$this->url?$serializedData");

            if ($getNumber == 3) {
                $parsedResponse = explode(':', $result);
                return $parsedResponse[1];
            }

            if ($getNumber == 1) {
                $parsedResponse = explode(':', $result);
                return OrdersHelper::requestArray($parsedResponse[0]);
            }
            $json_string = stripslashes(html_entity_decode($result));
            $result = json_decode(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $json_string), true);
            return $result;
        } else {
            $options = array(
                'http' => array(
//                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method' => 'POST',
                    'proxy' =>  'VtZNR9Hb:nXC9nQ45@45.147.246.121:64614',
                    'request_fulluri' => true,
                    'content' => $serializedData
                )
            );
            $context = stream_context_create($options);
            $result = file_get_contents($this->url, false, $context);
//            dd($result);
            if ($getNumber == 1) {
                return OrdersHelper::requestArray($result);
            }
            if (OrdersHelper::requestArray($result) == false) {
                $parsedResult = json_decode($result, true);
                return $parsedResult;
            } else {
                throw new \RuntimeException(OrdersHelper::requestArray($result));
//                return OrdersHelper::requestArray($result);
            }


//            return $result;
        }

//        $responseError = new SmsActivateErrors($result);
//        $check = $responseError->checkExist($result);
//
//        try {
//            if ($check) {
//                throw new \Exception($result);
//            }
//        } catch (\Exception $e) {
//            return $e->getResponseCode();
//        }
//
//        if ($parseAsJSON) {
//            return json_decode($result, true);
//        }

//        $parsedResponse = explode(':', $result);

//        if ($getNumber == 1) {
//            return array('id' => $parsedResponse[1], 'number' => $parsedResponse[2]);
//        }
//        if ($getNumber == 2) {
//            return array('status' => $parsedResponse[0], 'code' => $parsedResponse[1]);
//        }
//        if ($getNumber == 3) {
//            return array('status' => $parsedResponse[0]);
//        }
//        return $parsedResponse[1];
    }

    private function requestRent($data, $method, $parseAsJSON = null, $getNumber = null)
    {
        $method = strtoupper($method);

        if (!in_array($method, array('GET', 'POST'))) {
            throw new InvalidArgumentException('Method can only be GET or POST');
        }
        $serializedData = http_build_query($data);

        if ($method === 'GET') {
            $request_url = "$this->url?$serializedData";
            $result = file_get_contents($request_url);
        } else {
            $options = array(
                'http' => array(
                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method' => 'POST',
                    'content' => $serializedData
                )
            );
            $context = stream_context_create($options);
            $result = file_get_contents($this->url, false, $context);
        }
        if ($getNumber == 1) {
            $result = json_decode($result, true);
            return $result;
        }

        if ($parseAsJSON) {
            $result = json_decode($result, true);
//            dd($result);
            if (isset($result['message'])) {
                $responsError = new ErrorCodes($result['message']);
                $check = $responsError->checkExist($result['message']);  // раскоментить если необходимо включить исключения для Аренды
                if ($check) {
                    throw new RequestError($result['message']);
                }
            }
//            if ($getNumber == 3){
//                return $result['price'];
//            }else{
            return $result;

        }
        return $result;
    }
}


