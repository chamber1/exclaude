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

// Downloads page; open to guests (limits per plan, config/downloads.php).
Route::get('/parser', [App\Http\Controllers\ParserController::class, 'index'])->name('parser.index');

// Download API (queue + status polling), used by the /parser page.
Route::controller(App\Http\Controllers\DownloadController::class)->prefix('downloads')->name('downloads.')->group(function () {
    Route::get('/', 'index')->middleware('throttle:downloads-status')->name('index');
    Route::post('/inspect', 'inspect')->middleware('throttle:downloads-inspect')->name('inspect');
    Route::post('/', 'store')->middleware('throttle:downloads')->name('store');
    Route::get('/{task}', 'show')->whereUuid('task')->middleware('throttle:downloads-status')->name('show');
    Route::get('/{task}/file', 'file')->whereUuid('task')->middleware('signed:relative')->name('file');
});
