<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('auth')->group(function (): void {
    Route::view('/dashboard', 'dashboard')->name('dashboard');
    Route::view('/admin', 'admin')
        ->middleware('can:access-administration')
        ->name('admin');
});
