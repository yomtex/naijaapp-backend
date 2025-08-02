<?php
use Illuminate\Support\Facades\Route;
use App\Modules\Admin\Controllers\AdminAuthController;
use App\Modules\Admin\Controllers\DisputeController;

Route::prefix('admin')->group(function () {
    Route::post('login', [AdminAuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('disputes', [DisputeController::class, 'index']);
        Route::post('disputes/{id}/resolve', [DisputeController::class, 'resolve']);
    });
});
