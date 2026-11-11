<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\TechnicianController;
use App\Http\Controllers\Api\IntakeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\Tech\JobController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\VerifyIntakeKey;
use App\Http\Middleware\VerifyTwilioSignature;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Tenant status page (link sent by text; no login)
Route::get('/s/{token}', [StatusController::class, 'show'])->name('status');

// Intake from axiom-properties.com (server-to-server, shared key)
Route::prefix('api/intake')->middleware([VerifyIntakeKey::class, 'throttle:60,1'])->group(function () {
    Route::post('/requests', [IntakeController::class, 'store']);
    Route::post('/photo-url', [IntakeController::class, 'photoUrl']);
});

// Twilio webhooks
Route::prefix('webhooks')->middleware(VerifyTwilioSignature::class)->group(function () {
    Route::post('/sms', [WebhookController::class, 'sms']);
    Route::post('/sms/status', [WebhookController::class, 'smsStatus']);
    Route::post('/voice', [WebhookController::class, 'voice']);
});

Route::middleware('auth')->group(function () {
    Route::get('/', [AuthController::class, 'home']);

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/locations/{location}/toggle', [DashboardController::class, 'toggleLocation'])->name('locations.toggle');
        Route::get('/people', [TechnicianController::class, 'index'])->name('technicians');
        Route::get('/people/new', [TechnicianController::class, 'create'])->name('technicians.create');
        Route::post('/people', [TechnicianController::class, 'store'])->name('technicians.store');
        Route::get('/people/{user}', [TechnicianController::class, 'edit'])->name('technicians.edit');
        Route::put('/people/{user}', [TechnicianController::class, 'update'])->name('technicians.update');
    });

    Route::middleware('role:admin,scheduler')->group(function () {
        Route::get('/queue', [QueueController::class, 'index'])->name('queue');
        Route::get('/work-orders/{workOrder}', [QueueController::class, 'show'])->name('queue.show');
        Route::post('/work-orders/{workOrder}/book', [QueueController::class, 'book'])->name('queue.book');
        Route::post('/work-orders/{workOrder}/reoffer', [QueueController::class, 'reoffer'])->name('queue.reoffer');
        Route::patch('/work-orders/{workOrder}', [QueueController::class, 'update'])->name('queue.update');
        Route::post('/work-orders/{workOrder}/resolve', [QueueController::class, 'resolve'])->name('queue.resolve');
        Route::post('/work-orders/{workOrder}/cancel', [QueueController::class, 'cancel'])->name('queue.cancel');
        Route::post('/work-orders/{workOrder}/message', [QueueController::class, 'message'])->name('queue.message');
    });

    Route::middleware('role:tech,admin,scheduler')->prefix('tech')->name('tech.')->group(function () {
        Route::get('/', [JobController::class, 'today'])->name('today');
        Route::get('/jobs/{workOrder}', [JobController::class, 'show'])->name('show');
        Route::post('/jobs/{workOrder}/on-my-way', [JobController::class, 'onMyWay'])->name('onmyway');
        Route::post('/jobs/{workOrder}/start', [JobController::class, 'start'])->name('start');
        Route::post('/jobs/{workOrder}/complete', [JobController::class, 'complete'])->name('complete');
        Route::post('/jobs/{workOrder}/message', [JobController::class, 'message'])->name('message');
        Route::post('/jobs/{workOrder}/photo-url', [JobController::class, 'photoUrl'])->name('photo.url');
        Route::post('/jobs/{workOrder}/photos', [JobController::class, 'photoSaved'])->name('photo.saved');
    });
});
