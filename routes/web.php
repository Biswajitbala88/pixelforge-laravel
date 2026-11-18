<?php

use App\Http\Controllers\Admin\FinancialReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\Webhooks\PixelforgeWebhookController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [GenerationController::class, 'index'])->name('dashboard');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('generations.store');
    Route::post('credits', [CreditController::class, 'store'])->name('credits.store');

    Route::get('admin/report', FinancialReportController::class)
        ->middleware(EnsureUserIsAdmin::class)
        ->name('admin.report');
});

// Public, signature-verified. CSRF is disabled for this path in bootstrap/app.php.
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

require __DIR__.'/settings.php';
