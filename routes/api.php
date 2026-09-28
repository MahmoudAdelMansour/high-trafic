<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\LikeController;
use App\Http\Controllers\PostController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/follow/{user}', [FollowController::class, 'store'])->whereNumber('user')->name('follows.store');
    Route::delete('/follow/{user}', [FollowController::class, 'destroy'])->whereNumber('user')->name('follows.destroy');

    Route::get('/feed', FeedController::class)->name('feed');

    Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
    Route::get('/posts/{post}', [PostController::class, 'show'])->whereNumber('post')->name('posts.show');
    Route::delete('/posts/{post}', [PostController::class, 'destroy'])->whereNumber('post')->can('delete', 'post')->name('posts.destroy');

    Route::post('/posts/{post}/like', [LikeController::class, 'store'])->whereNumber('post')->name('posts.like');
    Route::delete('/posts/{post}/like', [LikeController::class, 'destroy'])->whereNumber('post')->name('posts.unlike');
});
