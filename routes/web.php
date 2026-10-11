<?php

use App\Http\Controllers\TicketMutationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('auth')->group(function (): void {
    Route::post('/tickets', [TicketMutationController::class, 'store'])->name('tickets.store');
    Route::patch('/tickets/{ticket}/assignment', [TicketMutationController::class, 'assign'])->name('tickets.assign');
    Route::patch('/tickets/{ticket}/status', [TicketMutationController::class, 'changeStatus'])->name('tickets.change-status');
    Route::view('/dashboard', 'dashboard')->name('dashboard');
    Route::view('/admin', 'admin')
        ->middleware('can:access-administration')
        ->name('admin');
});
