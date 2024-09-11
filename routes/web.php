<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\ProductController;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});


//Telegram Webhook marshruti
Route::post('/telegram/webhook', [ProductController::class, 'handleWebhook']);
Route::post('/telegram/check', [ProductController::class, 'handleUserInput']);

// Webhookni sozlash uchun marshrut
Route::get('/set-webhook', function () {
    $token = env('TELEGRAPH_BOT_TOKEN');
    $url = "https://api.telegram.org/bot$token/setWebhook";
    $webhookUrl = env('APP_URL') . '/telegram/webhook';

    $data = [
        'url' => $webhookUrl,
    ];

    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data),
        ],
    ];

    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);

    return $result === FALSE ? 'Webhookni o\'rnatishda xatolik yuz berdi' : 'Webhook muvaffaqiyatli o\'rnatildi';
});


Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
