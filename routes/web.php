<?php

use App\Http\Controllers\ProductImageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/image-finder');
Route::prefix('image-finder')->name('image-finder.')->group(function (): void {
    Route::get('/', [ProductImageController::class, 'index'])->name('index');
    Route::get('/batches', [ProductImageController::class, 'batches'])->name('batches.index');
    Route::post('/batches', [ProductImageController::class, 'store'])->middleware('throttle:10,1')->name('batches.store');
    Route::delete('/batches', [ProductImageController::class, 'clearBatches'])->name('batches.destroy-all');
    Route::delete('/batches/{batchId}', [ProductImageController::class, 'destroyBatch'])->name('batches.destroy');
    Route::get('/batches/{batchId}', [ProductImageController::class, 'show'])->name('batches.show');
    Route::post('/batches/{batchId}/resume', [ProductImageController::class, 'resume'])->middleware('throttle:10,1')->name('batches.resume');
    Route::post('/batches/{batchId}/cancel', [ProductImageController::class, 'cancel'])->middleware('throttle:10,1')->name('batches.cancel');
    Route::get('/batches/{batchId}/export.json', [ProductImageController::class, 'exportJson'])->name('batches.export');
    Route::get('/batches/{batchId}/archive.zip', [ProductImageController::class, 'archive'])->name('batches.archive');
    Route::get('/candidates/{candidateId}/preview', [ProductImageController::class, 'preview'])->name('candidates.preview');
    Route::post('/candidates/{candidateId}/approve', [ProductImageController::class, 'approve'])->middleware('throttle:20,1')->name('candidates.approve');
    Route::post('/candidates/{candidateId}/reject', [ProductImageController::class, 'reject'])->middleware('throttle:20,1')->name('candidates.reject');
});
