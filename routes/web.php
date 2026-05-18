<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


// Route::get('/stress-test', function () {
//     return response()->json([
//         'status' => 'ok'
//     ]);
// });
