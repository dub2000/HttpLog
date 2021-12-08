<?php
use Illuminate\Support\Facades\Route;
use Dub2000\HttpLog\Http\Controllers\HttpLogController;

Route::get('http-log', [HttpLogController::class, 'index'])->name("httplogs.index");;

Route::delete('http-log/delete', [HttpLogController::class, 'delete'])->name("httplogs.deletelogs");
