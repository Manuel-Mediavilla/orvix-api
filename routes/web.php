<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'Orvix API',
        'status'  => 'ok',
        'endpoints' => [
            'health' => '/up',
            'proxy'  => 'POST /api/ai/proxy',
        ],
    ]);
});
