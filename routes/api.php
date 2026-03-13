<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ArrivalsController;
use App\Http\Controllers\SubscriptionsController;
use App\Http\Middleware\JwtAuth;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\ScannerController;
use App\Http\Controllers\PurchaseController;

// --- Simple health & DB checks ---
Route::get('/health', fn () => response()->json(['ok' => true, 'ts' => now()]));

Route::get('/dbcheck', function () {
    try {
        DB::connection()->getPdo();
        return ['db' => 'ok'];
    } catch (\Throwable $e) {
        return response()->json(['db' => 'fail', 'error' => $e->getMessage()], 500);
    }
});

// --- Auth (public) ---
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);
Route::post('/support/send', [SupportController::class, 'send']);

// --- Protected routes (JWT required) ---
Route::middleware([JwtAuth::class])->group(function () {
    Route::get('/profile', [ProfileController::class, 'me']);
    Route::get('/subscriptions/active', [SubscriptionsController::class, 'activeByMediaUid']);
    Route::get('/ticket', [TicketController::class, 'buyTicket']);

    // Purchase
    Route::post('/tickets/purchase', [PurchaseController::class, 'purchaseTicket']);
    Route::post('/subscriptions/purchase', [PurchaseController::class, 'purchaseSubscription']);
});

Route::post('/scanner/scan', [ScannerController::class, 'scan']);

// --- Public demo endpoint ---
Route::get('/arrivals', [ArrivalsController::class, 'byStation']);