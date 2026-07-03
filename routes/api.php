<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — CatatUang
|--------------------------------------------------------------------------
*/

// ─── Public (unauthenticated) ──────────────────────────────
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/firebase/login', [AuthController::class, 'firebaseLogin']);

// Google OAuth
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);

// ─── Protected (require Sanctum token) ────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Wallets
    Route::get('/wallets', [WalletController::class, 'index']);
    Route::post('/wallets', [WalletController::class, 'store']);

    // Transactions — AI parsing
    Route::post('/transactions/parse', [TransactionController::class, 'parse']);
    Route::post('/transactions/parse-receipt', [TransactionController::class, 'parseReceipt']);

    // Transactions — CRUD & listing
    Route::get('/transactions/today', [TransactionController::class, 'today']);
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::put('/transactions/{id}', [TransactionController::class, 'update']);
    Route::delete('/transactions/{id}', [TransactionController::class, 'destroy']);

    // Dashboard
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    // Exports
    Route::get('/export/pdf', [\App\Http\Controllers\ExportController::class, 'pdf']);
    Route::get('/export/excel', [\App\Http\Controllers\ExportController::class, 'excel']);
    Route::get('/export/word', [\App\Http\Controllers\ExportController::class, 'word']);
});
