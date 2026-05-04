<?php

use App\Http\Controllers\LandingController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Modules\BusinessSettingsModule\Entities\BusinessSettings;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/link', function () {
    $target = storage_path('app/public');
    $link = public_path('storage');

    if (!File::exists($link)) {
        File::makeDirectory($link, 0755, true); // Create the public/storage folder if not exists
        File::copyDirectory($target, $link);     // Copy files
        return 'Copied storage files (symlink disabled)';
    }

    return 'Storage already available.';
});


Route::get('lang/{locale}', [LandingController::class, 'lang'])->name('lang');
Route::get('/', [LandingController::class, 'home'])->name('home');
Route::get('page/about-us', [LandingController::class, 'aboutUs'])->name('page.about-us');
Route::get('page/privacy-policy', [LandingController::class, 'privacyPolicy'])->name('page.privacy-policy');
Route::get('page/terms-and-conditions', [LandingController::class, 'termsAndConditions'])->name('page.terms-and-conditions');
Route::get('page/contact-us', [LandingController::class, 'contactUs'])->name('page.contact-us');
Route::get('page/cancellation-policy', [LandingController::class, 'cancellationPolicy'])->name('page.cancellation-policy');
Route::get('page/refund-policy', [LandingController::class, 'refundPolicy'])->name('page.refund-policy');
Route::get('maintenance-mode', [LandingController::class, 'maintenanceMode'])->name('maintenance-mode');

Route::fallback(function () {
    return redirect('admin/auth/login');
});


// Route::get('/storage-link', function () {
//         Artisan::call('storage:link');
//         return "Storage link created successfully!";
// });

Route::get('/clear', function () {
    Artisan::call('optimize:clear');
    Artisan::call('cache:clear');
    Artisan::call('config:clear');
    Artisan::call('route:clear');
    Artisan::call('view:clear');
    return 'Application cache cleared successfully!';
});


Route::get('test', function () {});
