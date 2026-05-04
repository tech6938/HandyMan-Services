<?php

use Illuminate\Support\Facades\Route;
use Modules\BookingModule\Http\Controllers\Api\V1\Customer\BookingController;
use Modules\BookingModule\Http\Controllers\Api\V1\Provider\BookingController as ProviderBookingController;
use Modules\BookingModule\Http\Controllers\Api\V1\Serviceman\BookingController as ServicemanBookingController;

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

Route::group(['prefix' => 'customer', 'as' => 'customer.', 'namespace' => 'Api\V1\Customer', 'middleware' => ['auth:api']], function () {
    Route::group(['prefix' => 'booking', 'as' => 'booking.'], function () {
        Route::get('/', [BookingController::class, 'index']);
        Route::post('/testapi', [BookingController::class, 'testApi']);
        Route::get('/{booking_id}', [BookingController::class, 'show']);
        Route::get('single/{booking_id}', [BookingController::class, 'singleDetails']);
        Route::post('request/send', [BookingController::class, 'placeRequest'])->middleware('hitLimiter')->withoutMiddleware('auth:api');
        Route::put('status-update/{booking_id}', [BookingController::class, 'statusUpdate']);
        Route::post('cancel-single-service/{child_booking_id}', [BookingController::class, 'cancelSingleService']);
        Route::post('single-repeat-cancel/{repeat_id}', [BookingController::class, 'singleBookingCancel']);
        Route::post('track/{readable_id}', [BookingController::class, 'track'])->withoutMiddleware('auth:api');
        Route::post('store-offline-payment-data', [BookingController::class, 'storeOfflinePaymentData'])->withoutMiddleware('auth:api');
        Route::post('switch-payment-method', [BookingController::class, 'switchPaymentMethod'])->withoutMiddleware('auth:api');
    });
});
Route::any('digital-payment-booking-response', [BookingController::class, 'digitalPaymentBookingResponse']);

Route::group(['prefix' => 'admin', 'as' => 'admin.', 'namespace' => 'Api\V1\Admin', 'middleware' => ['auth:api']], function () {
    Route::group(['prefix' => 'booking', 'as' => 'booking.'], function () {
        Route::post('/', 'BookingController@index');
        Route::get('{id}', 'BookingController@show');
        Route::put('status-update/{booking_id}', 'BookingController@status_update');
        Route::put('schedule-update/{booking_id}', 'BookingController@schedule_update');
        Route::get('data/download', 'BookingController@download');
    });
});


Route::group(['prefix' => 'provider', 'as' => 'provider.', 'namespace' => 'Api\V1\Provider', 'middleware' => ['auth:api']], function () {
    Route::group(['prefix' => 'booking', 'as' => 'booking.'], function () {
        Route::post('/', 'BookingController@index');
        Route::get('{id}', 'BookingController@show');
        Route::get('single/{id}', 'BookingController@singleDetails');
        Route::put('request-accept/{booking_id}', 'BookingController@requestAccept');
        Route::post('request-ignore/{booking_id}', 'BookingController@requestIgnore');
        Route::post('single-repeat-cancel/{repeat_id}', 'BookingController@singleBookingCancel');
        Route::put('single-repeat-status-update/{repeat_id}', 'BookingController@singleBookingStatusUpdate');
        Route::put('status-update/{booking_id}', 'BookingController@statusUpdate');
        Route::put('schedule-update/{booking_id}', 'BookingController@scheduleUpdate');
        Route::put('assign-serviceman/{booking_id}', 'BookingController@assignServiceman');
        Route::get('data/download', 'BookingController@download');

        Route::get('opt/notification-send', 'BookingController@notificationSend');

        Route::get('service/info', [ProviderBookingController::class, 'getServiceInfo']);
        Route::put('service/edit/update-booking', [ProviderBookingController::class, 'updateBooking']);
        Route::put('repeat/service/edit/update-booking', [ProviderBookingController::class, 'updateBookingRepeat']);
        Route::put('service/edit/remove-service', [ProviderBookingController::class, 'removeService']);
    });
});


Route::group(['prefix' => 'serviceman', 'as' => 'serviceman.', 'namespace' => 'Api\V1\Serviceman', 'middleware' => ['auth:api']], function () {
    Route::group(['prefix' => 'booking', 'as' => 'booking.'], function () {
        Route::put('status-update/{booking_id}', 'BookingController@statusUpdate');
        Route::put('single-repeat-status-update/{booking_id}', 'BookingController@singleBookingStatusUpdate');
        Route::put('payment-status-update/{booking_id}', 'BookingController@paymentStatusUpdate');
        Route::get('list', 'BookingController@bookingList');
        Route::get('detail/{id}', 'BookingController@bookingDetails');
        Route::get('single/detail/{id}', 'BookingController@singleBookingDetails');

        Route::get('opt/notification-send', 'BookingController@notificationSend');

        Route::get('service/info', [ServicemanBookingController::class, 'getServiceInfo']);
        Route::put('service/edit/update-booking', [ServicemanBookingController::class, 'updateBooking']);
        Route::put('repeat/service/edit/update-booking', [ServicemanBookingController::class, 'updateBookingRepeat']);
        Route::put('service/edit/remove-service', [ServicemanBookingController::class, 'removeService']);
    });
});
