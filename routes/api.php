<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\JwtMiddleware;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::prefix('auth')->group(function () {

    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);

    Route::middleware('jwt.verify')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// For all users
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);

// Protected routes for ADMIN
Route::middleware(['jwt.verify', 'admin'])->group(function () {
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
});

Route::middleware('jwt.verify')->prefix('cart')->group(function () {

    Route::get('/', [CartController::class, 'index']);
    Route::post('/add', [CartController::class, 'add']);
    Route::post('/remove', [CartController::class, 'remove']);
    Route::post('/update', [CartController::class, 'update']);
});


Route::middleware('jwt.verify')->prefix('orders')->group(function () {

    // User routes
    Route::get('/history', [OrderController::class, 'orderHistory']);
    Route::post('/details', [OrderController::class, 'orderDetails']);
    Route::post('/create', [OrderController::class, 'create']);

    // أهم تعديل: cancelOrder لازم يكون مع ID
    Route::post('/cancel/{id}', [OrderController::class, 'cancelOrder']);

    // Admin routes
    Route::middleware('admin')->group(function () {
        Route::post('/updateStatus', [OrderController::class, 'updateStatus']);
        Route::post('/', [OrderController::class, 'orders']);
    });
});


Route::prefix('payment')->middleware(['jwt.verify'])->group(function () {
    Route::post('/create-intent', [PaymentController::class, 'createPaymentIntent']);
    Route::post('/confirm', [PaymentController::class, 'confirmPayment']);
});


// Route::get('/test', function () {
//     return response()->json([
//         'message' => 'working'
//     ]);
// });


 // // محاكاة لعمل 3 سيرفرات 
                    // $workerId = ($this->order->id % 3) + 1;
                    // $targetQueue = "server_" . $workerId;
                    // // المهمة الثانوية :توليد فاتورة
                    // GenerateInvoicePDF::dispatch($this->order)->onQueue($targetQueue);

                    // logger()->info("Order #{$this->order->id}: Payment processed by {$this->queue}. Invoice sent to {$targetQueue}.");
                    //php artisan queue:work --queue=server_1
//php artisan queue:work --queue=server_2
//php artisan queue:work --queue=server_3