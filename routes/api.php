<?php

use App\Http\Controllers\AiProxyController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app_key_set' => !empty(config('app.key')),
        'openrouter_set' => !empty(config('services.openrouter.key') ?: getenv('OPENROUTER_API_KEY')),
    ]);
});

Route::post('/ai/proxy', [AiProxyController::class, 'handle'])
    ->middleware(['throttle:10,1']);
