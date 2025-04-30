<?php

namespace App\Http\Controllers\Api\v1;

use App\Dto\BotFactory;
use App\Helpers\ApiHelpers;
use App\Helpers\BotLogHelpers;
use App\Http\Controllers\Controller;
use App\Http\Resources\api\OrderResource;
use App\Http\Resources\api\RentResource;
use App\Models\Bot\SmsBot;
use App\Models\Rent\RentOrder;
use App\Models\User\SmsUser;
use App\Services\Activate\RentService;
use App\Services\External\BottApi;
use Illuminate\Http\Request;
use Exception;
use RuntimeException;

class RentController extends Controller
{
    /**
     * @var RentService
     */
    private RentService $rentService;

    public function __construct()
    {
        $this->rentService = new RentService();
    }

    /**
     * формирование страны доступные для аренды
     *
     * @param Request $request
     * @return array|string
     */
    public function getRentCountries(Request $request)
    {
        try {
            if (is_null($request->public_key))
                return ApiHelpers::error('Not found params: public_key');
            $bot = SmsBot::query()->where('public_key', $request->public_key)->first();
            if (empty($bot))
                return ApiHelpers::error('Not found module.');
            $botDto = BotFactory::fromEntity($bot);

            $countries = $this->rentService->getRentCountries($botDto);

            return ApiHelpers::success($countries);
        } catch (\RuntimeException $r) {
            BotLogHelpers::notifyBotLog('(🔴R ' . __FUNCTION__ . ' Activate): ' . $r->getMessage());
            return ApiHelpers::error('Ошибка получения данных провайдера');
        } catch (Exception $e) {
            BotLogHelpers::notifyBotLog('(🔴E ' . __FUNCTION__ . ' Activate): ' . $e->getMessage());
            \Log::error($e->getMessage());
            return ApiHelpers::error('Rent countries error');
        }
    }

    /**
     * формирование сервисов доступных для аренды
     *
     * @param Request $request
     * @return array|string
     */
    public function getRentServices(Request $request)
    {
        try {
            if (is_null($request->public_key))
                return ApiHelpers::error('Not found params: public_key');
            if (is_null($request->country))
                return ApiHelpers::error('Not found params: country');
            $bot = SmsBot::query()->where('public_key', $request->public_key)->first();
            if (empty($bot))
                return ApiHelpers::error('Not found module.');
            $botDto = BotFactory::fromEntity($bot);

            $services = $this->rentService->getRentService($botDto, $request->country);

            return ApiHelpers::success($services);
        } catch (\RuntimeException $r) {
            BotLogHelpers::notifyBotLog('(🔴R ' . __FUNCTION__ . ' Activate): ' . $r->getMessage());
            return ApiHelpers::error('Ошибка получения данных провайдера');
        } catch (Exception $e) {
            BotLogHelpers::notifyBotLog('(🔴E ' . __FUNCTION__ . ' Activate): ' . $e->getMessage());
            \Log::error($e->getMessage());
            return ApiHelpers::error('Rent services error');
        }
    }

    /**
     * создание заказа на аренду
     *
     * @param Request $request
     * @return array|string
     */
    public function createRentOrder(Request $request)
    {
        try {
            if (is_null($request->user_id))
                return ApiHelpers::error('Not found params: user_id');
            $user = SmsUser::query()->where(['telegram_id' => $request->user_id])->first();
            if (is_null($request->public_key))
                return ApiHelpers::error('Not found params: public_key');
            if (is_null($request->country))
                return ApiHelpers::error('Not found params: country');
            if (is_null($request->service))
                return ApiHelpers::error('Not found params: service');
            if (is_null($request->time))
                return ApiHelpers::error('Not found params: time');
            $bot = SmsBot::query()->where('public_key', $request->public_key)->first();
            if (empty($bot))
                return ApiHelpers::error('Not found module.');
            if (is_null($request->user_secret_key))
                return ApiHelpers::error('Not found params: user_secret_key');
            $botDto = BotFactory::fromEntity($bot);

            $result = BottApi::checkUser(
                $request->user_id,
                $request->user_secret_key,
                $botDto->public_key,
                $botDto->private_key
            );
            if (!$result['result']) {
                throw new RuntimeException($result['message']);
            }
            if ($result['data']['money'] == 0) {
                throw new RuntimeException('Пополните баланс в боте');
            }

            $rentOrder = $this->rentService->create(
                $botDto,
                $request->service,
                $request->country,
                $request->time,
                $result['data']
            );

            return ApiHelpers::success($rentOrder);
        } catch (\RuntimeException $r) {
            BotLogHelpers::notifyBotLog('(🔴R ' . __FUNCTION__ . ' Activate): ' . $r->getMessage());
            return ApiHelpers::error($r->getMessage());
        } catch (Exception $e) {
            BotLogHelpers::notifyBotLog('(🔴E ' . __FUNCTION__ . ' Activate): ' . $e->getMessage());
            \Log::error($e->getMessage());
            return ApiHelpers::error('Create rent order error');
        }
    }

    /**
     * получить все заказы пользователя
     *
     * @param Request $request
     * @return array|string
     */
    public function getRentOrders(Request $request)
    {
        try {
            if (is_null($request->user_id))
                return ApiHelpers::error('Not found params: user_id');
            $user = SmsUser::query()->where(['telegram_id' => $request->user_id])->first();
            if (is_null($request->public_key))
                return ApiHelpers::error('Not found params: public_key');
            $bot = SmsBot::query()->where('public_key', $request->public_key)->first();
            if (empty($bot))
                return ApiHelpers::error('Not found module.');
            if (is_null($request->user_secret_key))
                return ApiHelpers::error('Not found params: user_secret_key');

            $botDto = BotFactory::fromEntity($bot);

            $result = BottApi::checkUser(
                $request->user_id,
                $request->user_secret_key,
                $botDto->public_key,
                $botDto->private_key
            );
            if (!$result['result']) {
                throw new RuntimeException($result['message']);
            }

            $result = RentResource::collection(RentOrder::query()->where(['user_id' => $user->id])->
            where(['bot_id' => $bot->id])->get());

            return ApiHelpers::success($result);
        } catch (\RuntimeException $r) {
            BotLogHelpers::notifyBotLog('(🔴R ' . __FUNCTION__ . ' Activate): ' . $r->getMessage());
            return ApiHelpers::error($r->getMessage());
        } catch (Exception $e) {
            BotLogHelpers::notifyBotLog('(🔴E ' . __FUNCTION__ . ' Activate): ' . $e->getMessage());
            \Log::error($e->getMessage());
            return ApiHelpers::error('Get rent orders error');
        }
    }

    /**
     * получить заказ
     *
     * @param Request $request
     * @return array|string
     */
    public function getRentOrder(Request $request)
    {
        try {
            if (is_null($request->user_id))
                return ApiHelpers::error('Not found params: user_id');
            $user = SmsUser::query()->where(['telegram_id' => $request->user_id])->first();
            if (is_null($request->order_id))
                return ApiHelpers::error('Not found params: order_id');
            $order = RentOrder::query()->where(['org_id' => $request->order_id])->first();
            if (is_null($request->user_secret_key))
                return ApiHelpers::error('Not found params: user_secret_key');
            if (is_null($request->public_key))
                return ApiHelpers::error('Not found params: public_key');
            $bot = SmsBot::query()->where('public_key', $request->public_key)->first();
            if (empty($bot))
                return ApiHelpers::error('Not found module.');

            $botDto = BotFactory::fromEntity($bot);
            $result = BottApi::checkUser(
                $request->user_id,
                $request->user_secret_key,
                $botDto->public_key,
                $botDto->private_key
            );
            if (!$result['result']) {
                throw new RuntimeException($result['message']);
            }

            $rent_order = RentOrder::query()->where(['org_id' => $request->order_id])->first();

            return ApiHelpers::success(RentResource::generateRentArray($rent_order));
        } catch (\RuntimeException $r) {
            BotLogHelpers::notifyBotLog('(🔴R ' . __FUNCTION__ . ' Activate): ' . $r->getMessage());
            return ApiHelpers::error($r->getMessage());
        } catch (Exception $e) {
            BotLogHelpers::notifyBotLog('(🔴E ' . __FUNCTION__ . ' Activate): ' . $e->getMessage());
            \Log::error($e->getMessage());
            return ApiHelpers::error('Get rent order error');
        }
    }

    /**
     * отменить аренду
     *
     * @param Request $request
     * @return array|string
     */
    public function closeRentOrder(Request $request)
    {
        try {
            if (is_null($request->user_id))
                return ApiHelpers::error('Not found params: user_id');
            $user = SmsUser::query()->where(['telegram_id' => $request->user_id])->first();
            if (is_null($request->order_id))
                return ApiHelpers::error('Not found params: order_id');
//            $order = RentOrder::query()->where(['org_id' => $request->order_id])->first();
            if (is_null($request->user_secret_key))
                return ApiHelpers::error('Not found params: user_secret_key');
            if (is_null($request->public_key))
                return ApiHelpers::error('Not found params: public_key');
            $bot = SmsBot::query()->where('public_key', $request->public_key)->first();
            if (empty($bot))
                return ApiHelpers::error('Not found module.');

            return \DB::transaction(function () use ($request, $bot) {
                $botDto = BotFactory::fromEntity($bot);
                $result = BottApi::checkUser(
                    $request->user_id,
                    $request->user_secret_key,
                    $botDto->public_key,
                    $botDto->private_key
                );
                if (!$result['result']) {
                    throw new RuntimeException($result['message']);
                }
                $order = RentOrder::query()->where(['org_id' => $request->order_id])->lockForUpdate()->first();
                $result = $this->rentService->cancel($botDto, $order, $result['data']);

                $rent_order = RentOrder::query()->where(['org_id' => $request->order_id])->first();

                return ApiHelpers::success(RentResource::generateRentArray($rent_order));
            });
        } catch (\RuntimeException $r) {
            BotLogHelpers::notifyBotLog('(🔴R ' . __FUNCTION__ . ' Activate): ' . $r->getMessage());
            return ApiHelpers::error($r->getMessage());
        } catch (Exception $e) {
            BotLogHelpers::notifyBotLog('(🔴E ' . __FUNCTION__ . ' Activate): ' . $e->getMessage());
            \Log::error($e->getMessage());
            return ApiHelpers::error('Close rent orders error');
        }
    }

    /**
     * @param Request $request
     * @return array|string
     */
    public function confirmRentOrder(Request $request)
    {
        try {
            if (is_null($request->user_id))
                return ApiHelpers::error('Not found params: user_id');
            $user = SmsUser::query()->where(['telegram_id' => $request->user_id])->first();
            if (is_null($request->order_id))
                return ApiHelpers::error('Not found params: order_id');
            $order = RentOrder::query()->where(['org_id' => $request->order_id])->first();
            if (is_null($request->user_secret_key))
                return ApiHelpers::error('Not found params: user_secret_key');
            if (is_null($request->public_key))
                return ApiHelpers::error('Not found params: public_key');
            $bot = SmsBot::query()->where('public_key', $request->public_key)->first();
            if (empty($bot))
                return ApiHelpers::error('Not found module.');

            $botDto = BotFactory::fromEntity($bot);
            $result = BottApi::checkUser(
                $request->user_id,
                $request->user_secret_key,
                $botDto->public_key,
                $botDto->private_key
            );
            if (!$result['result']) {
                throw new RuntimeException($result['message']);
            }

            $result = $this->rentService->confirm($botDto, $order, $result['data']);

            $rent_order = RentOrder::query()->where(['org_id' => $request->order_id])->first();

            return ApiHelpers::success(RentResource::generateRentArray($rent_order));
        } catch (\RuntimeException $r) {
            BotLogHelpers::notifyBotLog('(🔴R ' . __FUNCTION__ . ' Activate): ' . $r->getMessage());
            return ApiHelpers::error($r->getMessage());
        } catch (Exception $e) {
            BotLogHelpers::notifyBotLog('(🔴E ' . __FUNCTION__ . ' Activate): ' . $e->getMessage());
            \Log::error($e->getMessage());
            return ApiHelpers::error('Confirm rent orders error');
        }
    }

    /**
     * @param Request $request
     * @return array|string
     */
    public function getContinuePrice(Request $request)
    {
        try {
            if (is_null($request->user_id))
                return ApiHelpers::error('Not found params: user_id');
            $user = SmsUser::query()->where(['telegram_id' => $request->user_id])->first();
            if (is_null($request->order_id))
                return ApiHelpers::error('Not found params: order_id');
            $order = RentOrder::query()->where(['org_id' => $request->order_id])->first();
            if (is_null($request->time)) //надо ли этот параметр
                return ApiHelpers::error('Not found params: time');
            if (is_null($request->user_secret_key))
                return ApiHelpers::error('Not found params: user_secret_key');
            if (is_null($request->public_key))
                return ApiHelpers::error('Not found params: public_key');
            $bot = SmsBot::query()->where('public_key', $request->public_key)->first();
            if (empty($bot))
                return ApiHelpers::error('Not found module.');

            $botDto = BotFactory::fromEntity($bot);
            $result = BottApi::checkUser(
                $request->user_id,
                $request->user_secret_key,
                $botDto->public_key,
                $botDto->private_key
            );
            if (!$result['result']) {
                throw new RuntimeException($result['message']);
            }

            $result = $this->rentService->priceContinue($botDto, $order, $request->time);

            return ApiHelpers::success($result);
        } catch (\RuntimeException $r) {
            BotLogHelpers::notifyBotLog('(🔴R ' . __FUNCTION__ . ' Activate): ' . $r->getMessage());
            return ApiHelpers::error($r->getMessage());
        } catch (Exception $e) {
            BotLogHelpers::notifyBotLog('(🔴E ' . __FUNCTION__ . ' Activate): ' . $e->getMessage());
            \Log::error($e->getMessage());
            return ApiHelpers::error('Continue rent price error');
        }
    }

    /**
     * продление аренды
     *
     * @param Request $request
     * @return array|string
     */
    public function continueRent(Request $request)
    {
        try {
            if (is_null($request->user_id))
                return ApiHelpers::error('Not found params: user_id');
            $user = SmsUser::query()->where(['telegram_id' => $request->user_id])->first();
            if (is_null($request->order_id))
                return ApiHelpers::error('Not found params: order_id');
            $rent_order = RentOrder::query()->where(['org_id' => $request->order_id])->first();
            if (is_null($request->time))
                return ApiHelpers::error('Not found params: time');
            if (is_null($request->public_key))
                return ApiHelpers::error('Not found params: public_key');
            $bot = SmsBot::query()->where('public_key', $request->public_key)->first();
            if (empty($bot))
                return ApiHelpers::error('Not found module.');
            if (is_null($request->user_secret_key))
                return ApiHelpers::error('Not found params: user_secret_key');

            $botDto = BotFactory::fromEntity($bot);
            $result = BottApi::checkUser(
                $request->user_id,
                $request->user_secret_key,
                $botDto->public_key,
                $botDto->private_key
            );
            if (!$result['result']) {
                throw new RuntimeException($result['message']);
            }
            if ($result['data']['money'] == 0) {
                throw new RuntimeException('Пополните баланс в боте');
            }

            $this->rentService->continueRent($botDto, $rent_order, $request->time, $result['data']);

            $rent_order = RentOrder::query()->where(['org_id' => $request->order_id])->first();

            return ApiHelpers::success(RentResource::generateRentArray($rent_order));
        } catch (\RuntimeException $r) {
            BotLogHelpers::notifyBotLog('(🔴R ' . __FUNCTION__ . ' Activate): ' . $r->getMessage());
            return ApiHelpers::error($r->getMessage());
        } catch (Exception $e) {
            BotLogHelpers::notifyBotLog('(🔴E ' . __FUNCTION__ . ' Activate): ' . $e->getMessage());
            \Log::error($e->getMessage());
            return ApiHelpers::error('Continue rent order error');
        }
    }

    /**
     * @param Request $request
     * @return array|string
     */
    public function getTimePrice(Request $request)
    {
        try {
            if (is_null($request->user_secret_key))
                return ApiHelpers::error('Not found params: user_secret_key');
            if (is_null($request->public_key))
                return ApiHelpers::error('Not found params: public_key');
            $bot = SmsBot::query()->where('public_key', $request->public_key)->first();
            if (is_null($request->time))
                return ApiHelpers::error('Not found params: time');
            if (is_null($request->country))
                return ApiHelpers::error('Not found params: time');
            if (is_null($request->service))
                return ApiHelpers::error('Not found params: time');
            $botDto = BotFactory::fromEntity($bot);

            $time_price = $this->rentService->getTimePrice(
                $botDto,
                $request->country,
                $request->service,
                $request->time
            );

            return ApiHelpers::success($time_price);
        } catch (\RuntimeException $r) {
            BotLogHelpers::notifyBotLog('(🔴R ' . __FUNCTION__ . ' Activate): ' . $r->getMessage());
            return ApiHelpers::error('Ошибка получения данных провайдера');
        } catch (Exception $e) {
            BotLogHelpers::notifyBotLog('(🔴E ' . __FUNCTION__ . ' Activate): ' . $e->getMessage());
            \Log::error($e->getMessage());
            return ApiHelpers::error('Time price rent error');
        }
    }

    /**
     * Метод обновения кодов через вебхук
     *
     * @param Request $request
     * @return void
     */
    public function updateSmsRent(Request $request)
    {
        $hook_rent = $request->all();

        $this->rentService->updateSms($hook_rent);
    }
}
