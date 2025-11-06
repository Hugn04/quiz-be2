<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExampleController;
use App\Http\Controllers\ScoreController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth', [AuthController::class, 'auth']);
    Route::get('/logout', [AuthController::class, 'logout']);
    Route::get('/get-profile', [AuthController::class, 'getProfile']);
    Route::post('/update-profile', [AuthController::class, 'updateProfile']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::post('/get-users', [AuthController::class, 'getUsers']);


    Route::post('/create-example', [ExampleController::class, 'createExample']);
    Route::get('/getExampleQ', [ExampleController::class, 'getExampleFromQuiz']);
    Route::get('/getQuestion', [ExampleController::class, 'getQuestion']);
    Route::get('/getMyQuestion', [ExampleController::class, 'getMyQuestion']);
    Route::get('/get-myexample', [ExampleController::class, 'getMyExample']);
    Route::get('/get-favorite', [ExampleController::class, 'getMyFavorite']);
    Route::post('/update-myexample', [ExampleController::class, 'updateMyExample']);
    Route::delete('/delete-myexample', [ExampleController::class, 'deleteExapmle']);

    Route::post('/like', [ExampleController::class, 'updateLike']);
    Route::post('/favorited', [ExampleController::class, 'updateFavorite']);

    Route::post('/update-score', [ScoreController::class, 'updateScore']);
    Route::get('/get-score', [ScoreController::class, 'getScores']);
});
Route::get('/get-example', [ExampleController::class, 'getAllExample']);
Route::post('/login', [AuthController::class, 'login']);

Route::post('/verify_register', [AuthController::class, 'verifyRegister']);
Route::post('/register', [AuthController::class, 'register']);

Route::post('/verify_forgot_password', [AuthController::class, 'verifyForgotPassword']);
Route::post('/forgot_password', [AuthController::class, 'forgotPassword']);

Route::post('/upload_image', [ExampleController::class, 'uploadImage']);
