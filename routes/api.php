<?php

use App\Http\Controllers\Api\AdminTeamController;
use App\Http\Controllers\Api\AdminAccountController;
use App\Http\Controllers\Api\AdminStatisticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ContactMessageController;
use App\Http\Controllers\Api\DonationController;
use App\Http\Controllers\Api\DonationResponseController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\SosRequestController;
use Illuminate\Support\Facades\Route;

Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::put('me', [AuthController::class, 'updateProfile']);
    Route::post('verify-password', [AuthController::class, 'verifyPassword']);
    Route::put('password', [AuthController::class, 'changePassword']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::put('initial-password', [AuthController::class, 'changeInitialPassword']);
    Route::post('contact-messages', [ContactMessageController::class, 'store'])
        ->middleware('throttle:5,1');

    Route::get('client-profile', [ClientController::class, 'show']);
    Route::post('client-profile', [ClientController::class, 'store']);
    Route::put('client-profile', [ClientController::class, 'update']);

    Route::apiResource('sos-requests', SosRequestController::class)
        ->only(['index', 'store', 'show']);
    Route::post('sos-requests/relay', [SosRequestController::class, 'relayStore'])
        ->middleware('throttle:30,1');
    Route::apiResource('donations', DonationController::class);
    Route::get('donation-responses', [DonationResponseController::class, 'mine']);
    Route::post(
        'donations/{donation}/responses',
        [DonationResponseController::class, 'store']
    );

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread', [NotificationController::class, 'unread']);
    Route::patch('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'read']);
    Route::delete('notifications', [NotificationController::class, 'destroyAll']);
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);

    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('statistics', AdminStatisticsController::class);
        Route::get('accounts', [AdminAccountController::class, 'index']);
        Route::post('teams', [AdminAccountController::class, 'storeTeam']);
        Route::get('accounts/{user}', [AdminAccountController::class, 'show']);
        Route::put('accounts/{user}', [AdminAccountController::class, 'update']);
        Route::post('accounts/{user}/message', [AdminAccountController::class, 'sendMessage'])
            ->middleware('throttle:10,1');
        Route::delete('accounts/{user}', [AdminAccountController::class, 'destroy']);

        Route::patch(
            'sos-teams/{team}',
            [AdminTeamController::class, 'updateSosTeam']
        );
        Route::patch(
            'donation-teams/{team}',
            [AdminTeamController::class, 'updateDonationTeam']
        );
    });

    Route::middleware(['password.changed', 'team.active:sos_team'])->group(function () {
        Route::get(
            'sos-team/requests/pending',
            [SosRequestController::class, 'pending']
        );
        Route::post(
            'sos-team/requests/{sosRequest}/accept',
            [SosRequestController::class, 'accept']
        );
        Route::post(
            'sos-team/requests/{sosRequest}/reject',
            [SosRequestController::class, 'reject']
        );
        Route::post(
            'sos-team/requests/{sosRequest}/fail',
            [SosRequestController::class, 'fail']
        );
    });

    Route::middleware(['password.changed', 'team.active:donation_team'])->group(function () {
        Route::get(
            'donation-team/responses',
            [DonationResponseController::class, 'teamIndex']
        );
        Route::post(
            'donation-team/responses/{donationResponse}/accept',
            [DonationResponseController::class, 'accept']
        );
        Route::post(
            'donation-team/responses/{donationResponse}/reject',
            [DonationResponseController::class, 'reject']
        );
        Route::get('donation-team/matches', [MatchController::class, 'index']);
        Route::post('donation-team/matches', [MatchController::class, 'store']);
        Route::post(
            'donation-team/matches/{matchedDonation}/accept',
            [MatchController::class, 'accept']
        );
        Route::post(
            'donation-team/matches/{matchedDonation}/reject',
            [MatchController::class, 'reject']
        );
    });
});
