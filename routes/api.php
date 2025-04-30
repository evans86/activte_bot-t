<?php

use App\Http\Controllers\Api\v1\BotController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\CountryController;
use App\Http\Controllers\Api\v1\UserController;
use App\Http\Controllers\Api\v1\ProductController;
use App\Http\Controllers\Api\v1\OrderController;
use App\Http\Controllers\Api\v1\RentController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/**
 * Роуты API (страны, операторы, сервисы), ресурсный подход
 */
Route::resources([
    'countries' => CountryController::class,
    'services' => ProductController::class,
]);

/**
 * Роуты API мультисервис
 */
Route::get('getCountries', [CountryController::class, 'getCountries']);
Route::get('getServices', [ProductController::class, 'getServices']);
Route::get('createMulti', [OrderController::class, 'createMulti'])->middleware('throttle_user_secret_key');

/**
 * Роуты API (пользователи)
 */
Route::get('setService', [ProductController::class, 'setService'])->middleware('throttle_user_secret_key');
Route::get('setLanguage', [UserController::class, 'setLanguage'])->middleware('throttle_user_secret_key');
Route::get('getUser', [UserController::class, 'getUser']);

/**
 * Роуты API (боты)
 */
Route::get('ping', [BotController::class, 'ping']);
Route::get('create', [BotController::class, 'create']);
Route::get('get', [BotController::class, 'get']);
Route::post('update', [BotController::class, 'update']);
Route::get('delete', [BotController::class, 'delete']);
Route::get('getSettings', [BotController::class, 'getSettings']);

/**
 * Роуты API (заказы (создание, получение, все))
 */
Route::get('createOrder', [OrderController::class, 'createOrder'])->middleware('throttle_user_secret_key');
Route::get('getOrder', [OrderController::class, 'getOrder']);
Route::get('orders', [OrderController::class, 'orders']);

/**
 * Роуты API (заказы (изменение статусов))
 */
Route::get('closeOrder', [OrderController::class, 'closeOrder'])->middleware('throttle_user_secret_key');
Route::get('secondSms', [OrderController::class, 'secondSms'])->middleware('throttle_user_secret_key');
Route::get('confirmOrder', [OrderController::class, 'confirmOrder'])->middleware('throttle_user_secret_key');

/**
 * Роуты API (аренда номеров))
 */
Route::get('getRentCountries', [RentController::class, 'getRentCountries']);
Route::get('getRentServices', [RentController::class, 'getRentServices']);
Route::get('createRentOrder', [RentController::class, 'createRentOrder'])->middleware('throttle_user_secret_key');
Route::get('getRentOrders', [RentController::class, 'getRentOrders'])->middleware('throttle_user_secret_key');
Route::get('getRentOrder', [RentController::class, 'getRentOrder'])->middleware('throttle_user_secret_key');
Route::get('closeRentOrder', [RentController::class, 'closeRentOrder']);
//Route::get('closeRentOrder', [RentController::class, 'closeRentOrder'])->middleware('throttle_user_secret_key');
Route::get('confirmRentOrder', [RentController::class, 'confirmRentOrder'])->middleware('throttle_user_secret_key');
Route::get('getContinuePrice', [RentController::class, 'getContinuePrice'])->middleware('throttle_user_secret_key');
Route::get('getTimePrice', [RentController::class, 'getTimePrice']);
Route::get('continueRent', [RentController::class, 'continueRent'])->middleware('throttle_user_secret_key');
Route::post('rent/updateSmsRent', [RentController::class, 'updateSmsRent'])->name('cashier.webhook'); //метод обновения кодов через вебхук




