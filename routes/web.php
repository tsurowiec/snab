<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Tools — each tool lives under resources/views/pages/tools/<tool>/
    Route::livewire('conversions', 'pages::tools.conversions.index')->name('conversions.index');
    Route::livewire('conversions/{account}', 'pages::tools.conversions.show')->name('conversions.show');
    Route::livewire('card-import', 'pages::tools.card-import.index')->name('card-import.index');
    Route::livewire('alior-payees', 'pages::tools.alior-payees.index')->name('alior-payees.index');
});

require __DIR__.'/settings.php';
