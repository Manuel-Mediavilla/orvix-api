<?php

use App\Http\Controllers\AiProxyController;
use Illuminate\Support\Facades\Route;

// Proxy seguro hacia Anthropic API
// Accesible en: POST /api/ai/proxy
Route::post('/ai/proxy', [AiProxyController::class, 'handle'])
    ->middleware(['throttle:10,1']); // doble protección con middleware nativo
