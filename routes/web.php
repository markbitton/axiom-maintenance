<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EfficiencyController;
use App\Http\Controllers\Admin\RatesController;
use App\Http\Controllers\Admin\TechnicianController;
use App\Http\Controllers\Api\IntakeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PartController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\Tech\ClockController;
use App\Http\Controllers\Tech\JobController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WorkOrderController;
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
        Route::post('/demo/reset', [DashboardController::class, 'resetDemo'])->name('demo.reset');
        Route::get('/people', [TechnicianController::class, 'index'])->name('technicians');
        Route::get('/people/new', [TechnicianController::class, 'create'])->name('technicians.create');
        Route::post('/people', [TechnicianController::class, 'store'])->name('technicians.store');
        Route::get('/people/{user}', [TechnicianController::class, 'edit'])->name('technicians.edit');
        Route::put('/people/{user}', [TechnicianController::class, 'update'])->name('technicians.update');
        Route::get('/rates', [RatesController::class, 'index'])->name('rates');
        Route::post('/rates', [RatesController::class, 'update'])->name('rates.update');
        Route::get('/efficiency', [EfficiencyController::class, 'index'])->name('efficiency');
    });

    Route::middleware('role:admin,scheduler')->group(function () {
        Route::get('/queue', [QueueController::class, 'index'])->name('queue');
        Route::get('/schedule', [ScheduleController::class, 'index'])->name('schedule');
        Route::post('/schedule/move', [ScheduleController::class, 'move'])->name('schedule.move');
        Route::post('/schedule/propose', [ScheduleController::class, 'propose'])->name('schedule.propose');
        Route::post('/schedule/approve', [ScheduleController::class, 'approve'])->name('schedule.approve');
        Route::get('/parts', [PartController::class, 'index'])->name('parts');
        Route::post('/parts', [PartController::class, 'store'])->name('parts.store');
        Route::get('/parts/{part}', [PartController::class, 'show'])->name('parts.show');
        Route::put('/parts/{part}', [PartController::class, 'update'])->name('parts.update');
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::post('/invoices/{invoice}/status', [InvoiceController::class, 'status'])->name('invoices.status');
        Route::post('/work-orders/{workOrder}/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/map', [ScheduleController::class, 'map'])->name('map');
        Route::get('/work-orders/{workOrder}/suggest', [ScheduleController::class, 'suggest'])->name('schedule.suggest');
        Route::get('/new-work-order', [WorkOrderController::class, 'create'])->name('work-orders.create');
        Route::post('/new-work-order', [WorkOrderController::class, 'store'])->name('work-orders.store');
        Route::get('/work-orders/{workOrder}', [QueueController::class, 'show'])->name('queue.show');
        Route::post('/work-orders/{workOrder}/book', [QueueController::class, 'book'])->name('queue.book');
        Route::post('/work-orders/{workOrder}/reoffer', [QueueController::class, 'reoffer'])->name('queue.reoffer');
        Route::patch('/work-orders/{workOrder}', [QueueController::class, 'update'])->name('queue.update');
        Route::post('/work-orders/{workOrder}/resolve', [QueueController::class, 'resolve'])->name('queue.resolve');
        Route::post('/work-orders/{workOrder}/cancel', [QueueController::class, 'cancel'])->name('queue.cancel');
        Route::post('/work-orders/{workOrder}/message', [QueueController::class, 'message'])->name('queue.message');
    });

    Route::middleware('role:tech,admin,scheduler')->group(function () {
        Route::get('/receipts/new', [ReceiptController::class, 'create'])->name('receipts.create');
        Route::post('/receipts', [ReceiptController::class, 'store'])->name('receipts.store');
        Route::get('/receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');
        Route::put('/receipts/{receipt}', [ReceiptController::class, 'update'])->name('receipts.update');
        Route::get('/receipts/{receipt}/photo', [ReceiptController::class, 'photo'])->name('receipts.photo');
    });

    Route::middleware('role:tech,admin,scheduler')->prefix('tech')->name('tech.')->group(function () {
        Route::get('/', [JobController::class, 'today'])->name('today');
        Route::get('/jobs/{workOrder}', [JobController::class, 'show'])->name('show');
        Route::post('/jobs/{workOrder}/on-my-way', [JobController::class, 'onMyWay'])->name('onmyway');
        Route::post('/jobs/{workOrder}/start', [JobController::class, 'start'])->name('start');
        Route::post('/jobs/{workOrder}/complete', [JobController::class, 'complete'])->name('complete');
        Route::post('/jobs/{workOrder}/pause', [JobController::class, 'pause'])->name('pause');
        Route::post('/clock/in', [ClockController::class, 'in'])->name('clock.in');
        Route::post('/clock/out', [ClockController::class, 'out'])->name('clock.out');
        Route::post('/break/start', [ClockController::class, 'breakStart'])->name('break.start');
        Route::post('/break/end', [ClockController::class, 'breakEnd'])->name('break.end');
        Route::post('/ping', [ClockController::class, 'ping'])->name('ping')->middleware('throttle:30,1');
        Route::post('/jobs/{workOrder}/message', [JobController::class, 'message'])->name('message');
        Route::post('/jobs/{workOrder}/photo-url', [JobController::class, 'photoUrl'])->name('photo.url');
        Route::post('/jobs/{workOrder}/photos', [JobController::class, 'photoSaved'])->name('photo.saved');
    });
});
