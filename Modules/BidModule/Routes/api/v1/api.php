<?php

use Illuminate\Support\Facades\Route;
use Modules\BidModule\Http\Controllers\APi\V1\Customer\PostBidController;
use Modules\BidModule\Http\Controllers\APi\V1\Customer\PostController;
use Modules\BidModule\Http\Controllers\APi\V1\Customer\BidChatController;
use Modules\BidModule\Http\Controllers\APi\V1\Provider\PostBidController as ProviderPostBidController;
use Modules\BidModule\Http\Controllers\APi\V1\Provider\PostController as ProviderPostController;
use Modules\BidModule\Http\Controllers\APi\V1\Provider\BidChatController as ProviderBidChatController;
use Modules\BidModule\Http\Controllers\APi\V1\Admin\BidChatController as AdminBidChatController;

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

Route::group(['prefix' => 'customer', 'namespace' => 'APi\V1\Customer', 'middleware' => ['auth:api', 'ensureBiddingIsActive']], function () {
    Route::group(['prefix' => 'post'], function () {
        Route::get('/', [PostController::class, 'index']);
        Route::get('/details/{id}', [PostController::class, 'show']);
        Route::post('/', [PostController::class, 'store']);

        Route::put('update-info', [PostController::class, 'updateInfo']);

        Route::group(['prefix' => 'bid'], function () {
            Route::get('/', [PostBidController::class, 'index']);
            Route::get('details', [PostBidController::class, 'show']);
            Route::put('update-status', [PostBidController::class, 'update']);

            // Bid Chat Routes
            Route::group(['prefix' => 'chat'], function () {
                Route::post('get-or-create-channel', [BidChatController::class, 'getOrCreateChannel']);
                Route::get('conversation', [BidChatController::class, 'conversation']);
                Route::post('send-message', [BidChatController::class, 'sendMessage']);
            });
        });
    });
});

Route::group(['prefix' => 'provider', 'namespace' => 'APi\V1\Provider', 'middleware' => ['auth:api', 'ensureBiddingIsActive']], function () {
    Route::group(['prefix' => 'post'], function () {
        Route::get('/', [ProviderPostController::class, 'index']);
        Route::get('details/{id}', [ProviderPostController::class, 'show']);
        Route::post('/', [ProviderPostController::class, 'decline']);

        Route::group(['prefix' => 'bid'], function () {
            Route::get('/', [ProviderPostBidController::class, 'index']);
            Route::post('/', [ProviderPostBidController::class, 'store']);
            Route::post('/withdraw', [ProviderPostBidController::class, 'withdraw']);

            // Bid Chat Routes
            Route::group(['prefix' => 'chat'], function () {
                Route::post('get-or-create-channel', [ProviderBidChatController::class, 'getOrCreateChannel']);
                Route::get('conversation', [ProviderBidChatController::class, 'conversation']);
                Route::post('send-message', [ProviderBidChatController::class, 'sendMessage']);
            });
        });
    });
});


Route::group(['prefix' => 'admin', 'namespace' => 'APi\V1\Admin', 'middleware' => ['auth:api']], function () {
    Route::group(['prefix' => 'bid'], function () {
        Route::group(['prefix' => 'chat'], function () {
            Route::get('get-channel', [AdminBidChatController::class, 'getChannel']);
            Route::get('conversation', [AdminBidChatController::class, 'conversation']);
            Route::post('send-message', [AdminBidChatController::class, 'sendMessage']);
        });
    });
});