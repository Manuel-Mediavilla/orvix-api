<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'Orvix API',
        'status'  => 'ok',
        'endpoints' => [
            'health'   => '/up',
            'briefing' => '/briefing.html',
            'proxy'    => 'POST /api/ai/proxy',
        ],
    ]);
});

Route::redirect('/briefing', '/briefing.html');
