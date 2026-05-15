<?php

use App\Http\Controllers\ServiceShareController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| وب مشترک دریافت کانفیگ با کد ۵ رقمی (مثلاً bale.cyou)
| APP_SHARE_PICKUP_ONLY=true — همهٔ ربات‌ها کد را در service_shares مشترک می‌نویسند.
|--------------------------------------------------------------------------
*/

Route::get('/', [ServiceShareController::class, 'lookup'])->name('service-share.lookup');
Route::post('/', [ServiceShareController::class, 'resolve'])->middleware('throttle:30,1')->name('service-share.resolve');

Route::get('/c', function (Request $request) {
    return redirect()->route('service-share.lookup', array_filter([
        'code' => $request->query('code'),
    ]), 301);
});
Route::post('/c', [ServiceShareController::class, 'resolve'])->middleware('throttle:30,1');

Route::get('/iran-access', function (Request $request) {
    return redirect()->route('service-share.lookup', array_filter([
        'code' => $request->query('code'),
    ]), 301);
});
