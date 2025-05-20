<?php

use App\Http\Controllers\InfoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('info')->group(function () {
    Route::get('/server', [InfoController::class, 'serverInfo']);
    Route::get('/client', [InfoController::class, 'clientInfo']);
    Route::get('/database', [InfoController::class, 'databaseInfo']);
});
