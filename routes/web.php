<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Tools — each tool lives under resources/views/pages/tools/<tool>/
    Route::livewire('conversions', 'pages::tools.conversions.index')->name('conversions.index');
    Route::livewire('conversions/{account}', 'pages::tools.conversions.show')->name('conversions.show');
});

require __DIR__.'/settings.php';
