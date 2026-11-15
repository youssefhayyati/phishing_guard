<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NoVncController;
use App\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;

// Public auth endpoints
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Authenticated endpoints
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/dashboard/overview', [DashboardController::class, 'overview']);
    Route::get('/campaigns', [CampaignController::class, 'index']);
    Route::post('/campaigns', [CampaignController::class, 'store']);
    Route::get('/campaigns/{id}', [CampaignController::class, 'show']);
    Route::patch('/campaigns/{id}/status', [CampaignController::class, 'updateStatus']);
    Route::post('/campaigns/{id}/deploy', [CampaignController::class, 'deploy']);
    Route::get('/campaigns/{id}/deployment', [CampaignController::class, 'deployment']);

    Route::get('/campaigns/{campaignId}/sessions', [CampaignSessionController::class, 'index']);
    Route::delete('/campaigns/{campaignId}/sessions/{sessionId}', [CampaignSessionController::class, 'destroy']);

    Route::get('/novnc/status', [NoVncController::class, 'status']);
    Route::post('/novnc/launch', [NoVncController::class, 'launch']);

    // Remote servers (IP + SSH credentials) — CRUD
    Route::get('/servers', [ServerController::class, 'index']);
    Route::post('/servers', [ServerController::class, 'store']);
    Route::get('/servers/{id}', [ServerController::class, 'show']);
    Route::post('/servers/{id}/test', [ServerController::class, 'test']);
    Route::put('/servers/{id}', [ServerController::class, 'update']);
    Route::delete('/servers/{id}', [ServerController::class, 'destroy']);
});
