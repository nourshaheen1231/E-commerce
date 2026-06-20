<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessOrder;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Services\RedisInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class OrderController extends Controller
{

    protected RedisInventoryService $inventoryService;

    public function __construct(RedisInventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    public function orders()
    {
        $user = Auth::user();

        $orders = Order::with('payment')->get()->map(function ($order) {
            return [
                'id' => $order->id,
                'total_price' => $order->total_price,
                'order_status' => $order->status,
                'payment_status' => $order->payment->status ?? 'no payment',
                'created_at' => $order->created_at,
            ];
        });

        return response()->json($orders, 200);
    }


    public function orderHistory()
    {
        $user = Auth::user();

        $orders = Order::where('user_id', $user->id)
            ->latest()
            ->get()
            ->map(function ($order) {
                $order->invoice_url = $order->invoice_path
                    ? asset('storage/' . $order->invoice_path)
                    : null;
                return $order;
            });

        return response()->json($orders, 200);
    }
    public function orderDetails(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|exists:orders,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $order = Order::where('user_id', $user->id)
            ->where('id', $request->id)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $orderItems = OrderItem::with('product')
            ->where('order_id', $order->id)
            ->get();

        return response()->json($orderItems, 200);
    }


    // public function create(Request $request)
    // {
    //     $user = Auth::user();

    //     $validator = Validator::make($request->all(), [
    //         'items' => 'required|array|min:1',
    //         'items.*' => 'integer|exists:cart_items,id',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'message' => 'Validation failed',
    //             'errors' => $validator->errors()
    //         ], 422);
    //     }

    //     $cart = $user->cart;
    //     if (!$cart) {
    //         return response()->json(['error' => 'Cart not found'], 404);
    //     }

    //     $cartItems = $cart->cartItems()
    //         ->whereIn('id', $request->items)
    //         ->with('product') // eager load product
    //         ->get();

    //     if ($cartItems->isEmpty()) {
    //         return response()->json(['error' => 'No valid cart items found'], 400);
    //     }

    //     if ($cartItems->count() !== count($request->items)) {
    //         return response()->json(['error' => 'Some cart items are invalid'], 400);
    //     }

    //     // تحقق أولي من الكمية مقابل المخزون قبل بدء الترانزاكشن
    //     foreach ($cartItems as $item) {
    //         if (!$item->product) {
    //             return response()->json(['error' => 'Product not found', 'cart_item_id' => $item->id], 400);
    //         }
    //         if ($item->quantity > $item->product->stock) {
    //             return response()->json([
    //                 'error' => 'Requested quantity exceeds available stock',
    //                 'product_name' => $item->product->name
    //             ], 400);
    //         }
    //     }

    //     DB::beginTransaction();

    //     try {
    //         $order = Order::create([
    //             'user_id' => $user->id,
    //             'total_price' => $cartItems->sum(fn($i) => $i->price * $i->quantity),
    //         ]);

    //         foreach ($cartItems as $item) {
    //             $product = Product::where('id', $item->product_id)->lockForUpdate()->first();
    //             // $product = Product::where('id', $item->product_id)->first();
    //             if (!$product) {
    //                 throw new \Exception("Product not found for id {$item->product_id}");
    //             }

    //             if ($product->stock < $item->quantity) {
    //                 throw new \Exception("Insufficient stock for product {$product->name}");
    //             }
    //             Log::info(now());
    //             sleep(2);
    //             $product->decrement('stock', $item->quantity);

    //             OrderItem::create([
    //                 'order_id' => $order->id,
    //                 'product_id' => $item->product_id,
    //                 'quantity' => $item->quantity,
    //                 'price' => $item->price,
    //             ]);
    //         }

    //         // $cart->cartItems()
    //         //     ->whereIn('id', $request->items)
    //         //     ->delete();

    //         DB::commit();

    //         return response()->json([
    //             'message' => 'Order created successfully',
    //             'order_id' => $order->id,
    //             'total_price' => $order->total_price
    //         ], 201);
    //     } catch (\Exception $e) {
    //         DB::rollBack();

    //         return response()->json([
    //             'error' => 'Something went wrong',
    //             'details' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    // Create Order After Adding Print Time
    // public function create(Request $request)
    // {
    //     $user = Auth::user();

    //     $validator = Validator::make($request->all(), [
    //         'items'   => 'required|array|min:1',
    //         'items.*' => 'integer|exists:cart_items,id',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'message' => 'Validation failed',
    //             'errors'  => $validator->errors()
    //         ], 422);
    //     }

    //     $cart = $user->cart;
    //     if (!$cart) {
    //         return response()->json(['error' => 'Cart not found'], 404);
    //     }

    //     $cartItemIds = collect($request->items);
    //     $cartItems = $cart->cartItems()
    //         ->whereIn('id', $cartItemIds)
    //         ->get();

    //     if ($cartItems->isEmpty()) {
    //         return response()->json(['error' => 'No valid cart items found'], 400);
    //     }

    //     if ($cartItems->count() !== $cartItemIds->count()) {
    //         return response()->json(['error' => 'Some cart items are invalid'], 400);
    //     }

    //     $requestStartTime = $_SERVER['REQUEST_TIME_FLOAT'];

    //     $dbStartTime = 0;
    //     $dbEndTime = 0;

    //     try {
    //         $dbStartTime = microtime(true);

    //         $order = DB::transaction(function () use ($user, $cartItems) {

    //             $productIds = $cartItems->pluck('product_id');
    //             $products = Product::whereIn('id', $productIds)
    //                 ->lockForUpdate()
    //                 ->get()
    //                 ->keyBy('id');

    //             foreach ($cartItems as $item) {
    //                 $product = $products->get($item->product_id);

    //                 if (!$product) {
    //                     throw new \Exception("Product not found for id {$item->product_id}");
    //                 }

    //                 if ($product->stock < $item->quantity) {
    //                     throw new \App\Exceptions\InsufficientStockException(
    //                         "Requested quantity exceeds available stock",
    //                         $product->name
    //                     );
    //                 }
    //             }

    //             $totalPrice = $cartItems->sum(fn($i) => $i->price * $i->quantity);

    //             $order = Order::create([
    //                 'user_id'     => $user->id,
    //                 'total_price' => $totalPrice,
    //             ]);

    //             foreach ($cartItems as $item) {
    //                 $product = $products->get($item->product_id);

    //                 // sleep(2);
    //                 $product->decrement('stock', $item->quantity);

    //                 OrderItem::create([
    //                     'order_id'   => $order->id,
    //                     'product_id' => $item->product_id,
    //                     'quantity'   => $item->quantity,
    //                     'price'      => $item->price,
    //                 ]);
    //             }

    //             return $order;
    //         });
    //         $dbEndTime = microtime(true);

    //         $totalPhpTime = round((microtime(true) - $requestStartTime) * 1000, 2);
    //         $dbTime = round(($dbEndTime - $dbStartTime) * 1000, 2);

    //         ProcessOrder::dispatch($order, $user->id, $request->scenario);
    //         // CartItem::where('cart_id', $user->cart->id)->delete();
    //         if ($user->cart) {
    //             $user->cart->cartItems()->delete();
    //         }

    //         return response()->json([
    //             'message'     => 'Order created successfully',
    //             'order_id'    => $order->id,
    //             'benchmarks'  => [
    //                 'total_php_time_ms' => $totalPhpTime,
    //                 'mysql_lock_time_ms' => $dbTime,
    //                 'php_overhead_ms' => $totalPhpTime - $dbTime
    //             ]
    //         ], 201);

    //         // return response()->json([
    //         //     'message'     => 'Order created successfully',
    //         //     'order_id'    => $order->id,
    //         //     'total_price' => $order->total_price
    //         // ], 201);

    //     } catch (\App\Exceptions\InsufficientStockException $e) {
    //         $dbEndTime = microtime(true);

    //         $totalPhpTime = round((microtime(true) - $requestStartTime) * 1000, 2);
    //         $dbTime = round(($dbEndTime - $dbStartTime) * 1000, 2);

    //         return response()->json([
    //             'error'        => $e->getMessage(),
    //             'product_name' => $e->getProductName(),
    //             'benchmarks'  => [
    //                 'total_php_time_ms' => $totalPhpTime,
    //                 'mysql_lock_time_ms' => $dbTime,
    //             ]
    //         ], 400);

    //         // return response()->json([
    //         //     'error'        => $e->getMessage(),
    //         //     'product_name' => $e->getProductName()
    //         // ], 400);
    //     } catch (\Exception $e) {
    //         Log::error('Order creation failed: ' . $e->getMessage());
    //         return response()->json([
    //             'error'   => 'Something went wrong',
    //             'details' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    // Create Order with Redis
    // public function create(Request $request)
    // {
    //     $user = Auth::user();

    //     $validator = Validator::make($request->all(), [
    //         'items'   => 'required|array|min:1',
    //         'items.*' => 'integer|exists:cart_items,id',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'message' => 'Validation failed',
    //             'errors'  => $validator->errors()
    //         ], 422);
    //     }

    //     $cart = $user->cart;
    //     if (!$cart) {
    //         return response()->json(['error' => 'Cart not found'], 404);
    //     }

    //     $cartItemIds = collect($request->items);
    //     $cartItems = $cart->cartItems()
    //         ->whereIn('id', $cartItemIds)
    //         ->get();

    //     if ($cartItems->isEmpty() || $cartItems->count() !== $cartItemIds->count()) {
    //         return response()->json(['error' => 'Invalid cart items'], 400);
    //     }

    //     $requestStartTime = $_SERVER['REQUEST_TIME_FLOAT'];
    //     $redisStartTime = 0;
    //     $redisEndTime = 0;

    //     try {
    //         $redisStartTime = microtime(true);

    //         $this->inventoryService->reserveItems($cartItems);

    //         $redisEndTime = microtime(true);

    //         $totalPrice = $cartItems->sum(fn($i) => $i->price * $i->quantity);

    //         $order = Order::create([
    //             'user_id'     => $user->id,
    //             'total_price' => $totalPrice,
    //         ]);

    //         $orderItemsData = [];
    //         foreach ($cartItems as $item) {
    //             $orderItemsData[] = [
    //                 'order_id'   => $order->id,
    //                 'product_id' => $item->product_id,
    //                 'quantity'   => $item->quantity,
    //                 'price'      => $item->price,
    //                 'created_at' => now(),
    //                 'updated_at' => now(),
    //             ];
    //         }
    //         OrderItem::insert($orderItemsData);

    //         ProcessOrder::dispatch($order, $user->id, $request->scenario);

    //         // 5. إرسال مهمة لتحديث المخزون الحقيقي في MySQL في الخلفية
    //         // (سيقوم الطابور بعمل $product->decrement() لاحقاً بدون تعطيل العميل)
    //         // $productsToSync = $cartItems->mapWithKeys(fn($item) => [$item->product_id => $item->quantity])->toArray();
    //         // SyncMysqlStock::dispatch($productsToSync);

    //         // 6. تفريغ السلة
    //         $user->cart->cartItems()->delete();

    //         // $totalPhpTime = round((microtime(true) - $requestStartTime) * 1000, 2);
    //         // $redisTime = round(($redisEndTime - $redisStartTime) * 1000, 2);

    //         return response()->json([
    //             'message'    => 'Order created successfully',
    //             'order_id'   => $order->id,
    //             'benchmarks' => [
    //                 'total_php_time_ms'  => 0,
    //                 'redis_reserve_time_ms' => 0,
    //                 'mysql_lock_time_ms' => 0,
    //                 'php_overhead_ms'    => 0 - 0
    //             ]
    //         ], 201);

    //     } catch (\App\Exceptions\InsufficientStockException $e) {
    //         // $redisEndTime = microtime(true);
    //         // $totalPhpTime = round((microtime(true) - $requestStartTime) * 1000, 2);
    //         // $redisTime = round(($redisEndTime - $redisStartTime) * 1000, 2);

    //         return response()->json([
    //             'error'        => $e->getMessage(),
    //             'product_name' => $e->getProductName(),
    //             'benchmarks'  => [
    //                 'total_php_time_ms'  => 0,
    //                 'redis_reserve_time_ms' => 0,
    //                 'mysql_lock_time_ms' => 0,
    //             ]
    //         ], 400);

    //     } catch (\Exception $e) {
    //         Log::error('Order creation failed: ' . $e->getMessage());

    //         return response()->json([
    //             'error'   => 'Something went wrong',
    //             'details' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    // Redis with Timing
    // public function create(Request $request)
    // {
    //     // 1. بداية الطلب الكلي (بالنانوثانية)
    //     $totalStart = hrtime(true);
    //     $profile = [];

    //     // --- المرحلة 1: التحقق من البيانات وجلب السلة ---
    //     $stepStart = hrtime(true);

    //     $user = Auth::user();

    //     $validator = Validator::make($request->all(), [
    //         'items'   => 'required|array|min:1',
    //         'items.*' => 'integer|exists:cart_items,id',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'message' => 'Validation failed',
    //             'errors'  => $validator->errors()
    //         ], 422);
    //     }

    //     $cart = $user->cart;
    //     if (!$cart) {
    //         return response()->json(['error' => 'Cart not found'], 404);
    //     }

    //     $cartItemIds = collect($request->items);
    //     $cartItems = $cart->cartItems()
    //         ->whereIn('id', $cartItemIds)
    //         ->get();

    //     if ($cartItems->isEmpty() || $cartItems->count() !== $cartItemIds->count()) {
    //         return response()->json(['error' => 'Invalid cart items'], 400);
    //     }

    //     $profile['1_validation_and_cart_ms'] = (hrtime(true) - $stepStart) / 1e6;

    //     try {
    //         // --- المرحلة 2: حجز المخزون عبر Redis ---
    //         $stepStart = hrtime(true);
    //         $this->inventoryService->reserveItems($cartItems);
    //         $profile['2_redis_reservation_ms'] = (hrtime(true) - $stepStart) / 1e6;

    //         // --- المرحلة 3: فتح الـ Transaction لقاعدة البيانات ---
    //         $stepStart = hrtime(true);
    //         DB::beginTransaction();
    //         $profile['3_db_transaction_start_ms'] = (hrtime(true) - $stepStart) / 1e6;

    //         $stepStart = hrtime(true);
    //         $totalPrice = $cartItems->sum(fn($i) => $i->price * $i->quantity);
    //         $order = Order::create([
    //             'user_id'     => $user->id,
    //             'total_price' => $totalPrice,
    //         ]);
    //         $profile['4_order_creation_ms'] = (hrtime(true) - $stepStart) / 1e6;

    //         $stepStart = hrtime(true);
    //         $now = now();
    //         $orderItemsData = [];
    //         foreach ($cartItems as $item) {
    //             $orderItemsData[] = [
    //                 'order_id'   => $order->id,
    //                 'product_id' => $item->product_id,
    //                 'quantity'   => $item->quantity,
    //                 'price'      => $item->price,
    //                 'created_at' => $now,
    //                 'updated_at' => $now,
    //             ];
    //         }
    //         OrderItem::insert($orderItemsData);
    //         $profile['5_order_items_creation_ms'] = (hrtime(true) - $stepStart) / 1e6;

    //         $stepStart = hrtime(true);
    //         $user->cart->cartItems()->delete();
    //         $profile['6_clear_cart_ms'] = (hrtime(true) - $stepStart) / 1e6;

    //         $stepStart = hrtime(true);
    //         DB::commit();
    //         $profile['7_db_commit_ms'] = (hrtime(true) - $stepStart) / 1e6;

    //         $stepStart = hrtime(true);
    //         ProcessOrder::dispatch($order, $user->id, $request->scenario);
    //         $profile['8_events_jobs_dispatch_ms'] = (hrtime(true) - $stepStart) / 1e6;

    //         $profile['total_php_time_ms'] = (hrtime(true) - $totalStart) / 1e6;
    //         $profile['redis_and_db_time_ms'] = $profile['2_redis_reservation_ms'] +
    //                                         $profile['4_order_creation_ms'] +
    //                                         $profile['5_order_items_creation_ms'];

    //         Log::info("Order Profiling [#{$order->id}]", $profile);

    //         return response()->json([
    //             'message'    => 'Order created successfully',
    //             'order_id'   => $order->id,
    //             'benchmarks' => $profile
    //         ], 201);

    //     } catch (\App\Exceptions\InsufficientStockException $e) {
    //         $profile['total_php_time_ms'] = (hrtime(true) - $totalStart) / 1e6;
    //         $profile['redis_and_db_time_ms'] = $profile['2_redis_reservation_ms'] ?? 0;

    //         return response()->json([
    //             'error'        => $e->getMessage(),
    //             'product_name' => $e->getProductName(),
    //             'benchmarks'   => $profile
    //         ], 400);

    //     } catch (\Exception $e) {
    //         DB::rollBack();

    //         $profile['total_php_time_ms'] = (hrtime(true) - $totalStart) / 1e6;

    //         Log::error('Order Profiling (Failed: Exception)', [
    //             'error' => $e->getMessage(),
    //             'metrics' => $profile
    //         ]);

    //         return response()->json([
    //             'error'      => 'Something went wrong',
    //             'details'    => $e->getMessage(),
    //             'benchmarks' => [
    //                 'total_php_time_ms' => $profile['total_php_time_ms'],
    //                 'redis_and_db_time_ms' => 0
    //             ]
    //         ], 500);
    //     }
    // }

    // Merging
    public function create(Request $request)
    {
        $totalStart = hrtime(true);
        $profile = [];
        $stepStart = hrtime(true);

        $user = Auth::user();

        $lock = Cache::lock('order-submit-user-' . $user->id, 10);

        if (!$lock->get()) {
            return response()->json([
                'message' => 'The previous request is currently being processed, please wait'
            ], 423);
        }

        try {

            $validator = Validator::make($request->all(), [
                'items'   => 'required|array|min:1',
                'items.*' => 'integer|exists:cart_items,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors'  => $validator->errors()
                ], 422);
            }

            $cart = $user->cart;
            if (!$cart) {
                return response()->json(['error' => 'Cart not found'], 404);
            }

            $cartItemIds = collect($request->items);
            $cartItems = $cart->cartItems()
                ->whereIn('id', $cartItemIds)
                ->get();

            if ($cartItems->isEmpty() || $cartItems->count() !== $cartItemIds->count()) {
                return response()->json(['error' => 'Invalid cart items'], 400);
            }

            $profile['1_validation_and_cart_ms'] = (hrtime(true) - $stepStart) / 1e6;

            $stepStart = hrtime(true);
            $this->inventoryService->reserveItems($cartItems);
            $profile['2_redis_reservation_ms'] = (hrtime(true) - $stepStart) / 1e6;

            $stepStart = hrtime(true);
            DB::beginTransaction();
            $profile['3_db_transaction_start_ms'] = (hrtime(true) - $stepStart) / 1e6;

            $stepStart = hrtime(true);
            $totalPrice = $cartItems->sum(fn($i) => $i->price * $i->quantity);
            $order = Order::create([
                'user_id'     => $user->id,
                'total_price' => $totalPrice,
            ]);
            $profile['4_order_creation_ms'] = (hrtime(true) - $stepStart) / 1e6;

            $stepStart = hrtime(true);
            $now = now();
            $orderItemsData = [];
            foreach ($cartItems as $item) {
                $orderItemsData[] = [
                    'order_id'   => $order->id,
                    'product_id' => $item->product_id,
                    'quantity'   => $item->quantity,
                    'price'      => $item->price,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            OrderItem::insert($orderItemsData);
            $profile['5_order_items_creation_ms'] = (hrtime(true) - $stepStart) / 1e6;

            $stepStart = hrtime(true);
            $user->cart->cartItems()->delete();
            $profile['6_clear_cart_ms'] = (hrtime(true) - $stepStart) / 1e6;

            // throw new \Exception("unexpected error occurred");

            $stepStart = hrtime(true);
            DB::commit();
            $profile['7_db_commit_ms'] = (hrtime(true) - $stepStart) / 1e6;

            $stepStart = hrtime(true);
            ProcessOrder::dispatch($order, $user->id, $request->scenario);
            $profile['8_events_jobs_dispatch_ms'] = (hrtime(true) - $stepStart) / 1e6;

            $profile['total_php_time_ms'] = (hrtime(true) - $totalStart) / 1e6;
            $profile['redis_and_db_time_ms'] = $profile['2_redis_reservation_ms'] +
                                            $profile['4_order_creation_ms'] +
                                            $profile['5_order_items_creation_ms'];

            Log::info("Order Profiling [#{$order->id}]", $profile);

            return response()->json([
                'message'    => 'Order created successfully',
                'order_id'   => $order->id,
                'benchmarks' => $profile
            ], 201);

        } catch (\App\Exceptions\InsufficientStockException $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();

            $profile['total_php_time_ms'] = (hrtime(true) - $totalStart) / 1e6;
            $profile['redis_and_db_time_ms'] = $profile['2_redis_reservation_ms'] ?? 0;

            return response()->json([
                'error'        => $e->getMessage(),
                'product_name' => $e->getProductName(),
                'benchmarks'   => $profile
            ], 400);

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();

            // ملاحظة: هنا ستحتاجين لاحقاً لإضافة دالة تعيد المخزون للـ Redis لأن العملية فشلت

            $profile['total_php_time_ms'] = (hrtime(true) - $totalStart) / 1e6;

            Log::error('Order Profiling (Failed: Exception)', [
                'error'   => $e->getMessage(),
                'metrics' => $profile
            ]);

            return response()->json([
                'error'      => 'Something went wrong',
                'details'    => $e->getMessage(),
                'benchmarks' => [
                    'total_php_time_ms' => $profile['total_php_time_ms'] ?? 0,
                    'redis_and_db_time_ms' => $profile['2_redis_reservation_ms'] ?? 0
                ]
            ], 500);

        } finally {
            if (isset($lock)) {
                $lock->release();
            }
        }
    }


    public function cancelOrder($id)
    {
        $user = Auth::user();

        $order = Order::where('user_id', $user->id)
            ->with('payment')
            ->find($id);

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if (in_array($order->status, ['shipped', 'delivered'])) {
            return response()->json(['message' => 'Order cannot be canceled'], 400);
        }

        if ($order->payment && $order->payment->status === 'paid') {

            $paymentController = new PaymentController();

            $refundSuccess = $paymentController->refund(
                $order->payment->stripe_payment_intent_id
            );

            if (!$refundSuccess) {
                return response()->json(['message' => 'Refund failed'], 400);
            }
        }

        $order->status = 'canceled';
        $order->save();

        return response()->json(['message' => 'Order canceled successfully']);
    }


    public function updateStatus(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'id'     => 'required|integer|exists:orders,id',
            'status' => 'required|in:pending,shipped,delivered,canceled',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $order = Order::find($request->id);
        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $order->status = $request->status;
        $order->save();

        return response()->json(['message' => 'Order status updated successfully'], 200);
    }
}
