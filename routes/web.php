<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

Route::controller(App\Http\Controllers\WebcamController::class)->prefix('webcam')->name('webcam.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/snapshots', 'store')->name('store');
    Route::get('/snapshots/{snapshot}', 'show')->name('show');
    Route::delete('/snapshots/{snapshot}', 'destroy')->name('destroy');
});

Route::controller(App\Http\Controllers\ParserController::class)->prefix('parser')->name('parser.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'download')->middleware('throttle:10,1')->name('download');
});
