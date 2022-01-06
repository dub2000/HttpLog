<?php
use Illuminate\Support\Facades\Route;
use Dub2000\HttpLog\Http\Controllers\TableController;

Route::get('http-log', [TableController::class, 'index'])->name("httplogs.index");

Route::delete('http-log/delete', [TableController::class, 'delete'])->name("httplogs.deletelogs");
