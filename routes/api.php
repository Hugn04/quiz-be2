<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExampleController;
use App\Http\Controllers\ScoreController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth', [AuthController::class, 'auth']);
    Route::get('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);


    Route::get('/get-profile', [UserController::class, 'getProfile']);
    Route::post('/update-profile', [UserController::class, 'updateProfile']);
    Route::post('/get-users', [UserController::class, 'getUsers']);


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
Route::post('/auth/google', [AuthController::class, 'googleLogin']);
Route::get('/get-example', [ExampleController::class, 'getAllExample']);
Route::post('/login', [AuthController::class, 'login']);

Route::post('/verify_register', [AuthController::class, 'verifyRegister']);
Route::post('/register', [AuthController::class, 'register']);

Route::post('/verify_forgot_password', [AuthController::class, 'verifyForgotPassword']);
Route::post('/forgot_password', [AuthController::class, 'forgotPassword']);

Route::post('/upload_image', [ExampleController::class, 'uploadImage']);
