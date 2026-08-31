<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\LoginCodeController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    // SECURITY: self-registration and public password reset are intentionally
    // DISABLED. Accounts are created and passwords reset by an admin/director
    // only (Сотрудники / Профиль). Login is the sole public entry point.
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // Второй шаг входа: персональный код ключевого сотрудника.
    Route::get('login/code', [LoginCodeController::class, 'create'])->name('login.code');
    Route::post('login/code', [LoginCodeController::class, 'store'])->name('login.code.store');
    Route::delete('login/code', [LoginCodeController::class, 'destroy'])->name('login.code.cancel');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    // Throttle: с угнанной сессией здесь можно было бы перебирать пароль.
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware('throttle:6,1');

    Route::put('password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
