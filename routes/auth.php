<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');

    // Tweede stap bij het inloggen (code uit de app of herstelcode).
    Route::get('tweestapsverificatie/code', [TwoFactorChallengeController::class, 'create'])
        ->name('two-factor.challenge');
    Route::post('tweestapsverificatie/code', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:10,1');

    // Uitnodiging voor een medewerker: eerste wachtwoord instellen.
    Route::get('uitnodiging/{token}', [InvitationController::class, 'show'])
        ->name('invitation.show');

    Route::post('uitnodiging', [InvitationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('invitation.store');
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

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    // Tweestapsverificatie instellen en beheren (eerst opnieuw het wachtwoord bevestigen).
    Route::middleware('password.confirm')->group(function () {
        Route::get('tweestapsverificatie', [TwoFactorController::class, 'show'])->name('two-factor.show');
        Route::post('tweestapsverificatie', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
        Route::post('tweestapsverificatie/herstelcodes', [TwoFactorController::class, 'recoveryCodes'])->name('two-factor.recovery-codes');
        Route::delete('tweestapsverificatie', [TwoFactorController::class, 'destroy'])->name('two-factor.destroy');
    });

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
