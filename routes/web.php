<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoLoginController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketMutationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('/demo/login', DemoLoginController::class)->middleware(['guest', 'throttle:public-demo'])->name('demo.login');

Route::middleware('auth')->group(function (): void {
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/create', [TicketController::class, 'create'])->name('tickets.create');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::get('/tickets/{ticket}/edit', [TicketController::class, 'edit'])->name('tickets.edit');
    Route::post('/tickets', [TicketMutationController::class, 'store'])->name('tickets.store');
    Route::patch('/tickets/{ticket}', [TicketMutationController::class, 'update'])->name('tickets.update');
    Route::post('/tickets/{ticket}/comments', [TicketMutationController::class, 'comment'])->name('tickets.comment');
    Route::patch('/tickets/{ticket}/assignment', [TicketMutationController::class, 'assign'])->name('tickets.assign');
    Route::patch('/tickets/{ticket}/status', [TicketMutationController::class, 'changeStatus'])->name('tickets.change-status');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::view('/admin', 'admin')
        ->middleware('can:access-administration')
        ->name('admin');
});
