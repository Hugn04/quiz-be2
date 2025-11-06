<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/email', function () {
    return view('emails.otp', ['otp' => 123]);
});
