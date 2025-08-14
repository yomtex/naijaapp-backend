<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Transaction\Controllers\TransactionController;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1'); // 5 attempts per minute
    Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:3,1'); // 3 attempts per minute
    Route::post('/refresh-token', [AuthController::class, 'refreshWithToken']);
    Route::post('/set-pin', [AuthController::class, 'setPin']);
    Route::post('/pin-login', [AuthController::class, 'pinLogin'])->middleware('throttle:5,1'); // 5 attempts per minute


});

Route::middleware('auth:api')->group(function () {
    // auth user
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/set-transfer-pin', [AuthController::class, 'setTransferPin']);

        // Transaction Routes (prefix optional, e.g., /transaction/send)
    Route::prefix('transfer')->group(function () {
        Route::post('/lookup', [TransactionController::class, 'lookupByEmail']);
        Route::post('/send', [TransactionController::class, 'sendMoney']);
        Route::post('/request', [TransactionController::class, 'requestMoney']);
        Route::post('/respond/{id}', [TransactionController::class, 'respondToRequest']);
        Route::post('/dispute/{id}', [TransactionController::class, 'openDispute']);
        Route::get('/history', [TransactionController::class, 'history']);
        Route::get('/cancel/{transactionId}', [TransactionController::class, 'cancelTransaction']);
    });
});
