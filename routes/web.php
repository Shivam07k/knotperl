<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminContentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\PublishingController;
use Illuminate\Support\Facades\Route;

// Laravel applies the web middleware group to this file via bootstrap/app.php.
Route::get('/login', [AdminAuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:5,1')->name('login.attempt');
Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');

// The signed webhook is called by Cloudflare, not an operator.
Route::post('/webhook/build', [PublishingController::class, 'webhook'])->name('webhook.build');

Route::middleware('admin.auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/validation', [DashboardController::class, 'validation'])->name('validation');
    Route::post('/local/reset', [DashboardController::class, 'resetFixture'])->name('local.reset');

    Route::post('/publishing/publish', [PublishingController::class, 'publish'])->name('publishing.publish');
    Route::post('/publishing/preview', [PublishingController::class, 'preview'])->name('publishing.preview');
    Route::get('/publishing/download/{id}', [PublishingController::class, 'download'])->name('publishing.download');
    Route::post('/publishing/rollback', [PublishingController::class, 'rollback'])->name('publishing.rollback');

    Route::get('/images', [ImageController::class, 'index'])->name('images.index');
    Route::get('/images/keys', [ImageController::class, 'keys'])->name('images.keys');
    Route::post('/images/upload', [ImageController::class, 'upload'])->name('images.upload');
    Route::post('/images/replace', [ImageController::class, 'replace'])->name('images.replace');
    Route::post('/images/delete', [ImageController::class, 'delete'])->name('images.delete');
    Route::post('/images/cleanup', [ImageController::class, 'cleanup'])->name('images.cleanup');

    Route::get('/content/{tab}', [AdminContentController::class, 'index'])->name('content.index')
        ->where('tab', 'Settings|Pages|Products|Sections|Navigation|Categories');
    Route::get('/content/{tab}/create', [AdminContentController::class, 'create'])->name('content.create')
        ->where('tab', 'Settings|Pages|Products|Sections|Navigation|Categories');
    Route::post('/content/{tab}', [AdminContentController::class, 'store'])->name('content.store')
        ->where('tab', 'Settings|Pages|Products|Sections|Navigation|Categories');
    Route::get('/content/{tab}/{row}/edit', [AdminContentController::class, 'edit'])->name('content.edit')
        ->where('tab', 'Settings|Pages|Products|Sections|Navigation|Categories');
    Route::put('/content/{tab}/{row}', [AdminContentController::class, 'update'])->name('content.update')
        ->where('tab', 'Settings|Pages|Products|Sections|Navigation|Categories');
    Route::delete('/content/{tab}/{row}', [AdminContentController::class, 'destroy'])->name('content.destroy')
        ->where('tab', 'Settings|Pages|Products|Sections|Navigation|Categories');
});
