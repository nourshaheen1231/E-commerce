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
use Illuminate\Support\Str;

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

    // Merging
    // public function create(Request $request)
    // {
    //     $totalStart = hrtime(true);
    //     $profile = [];
    //     $stepStart = hrtime(true);

    //     $user = Auth::user();

    //     $lock = Cache::lock('order-submit-user-' . $user->id, 10);

    //     if (!$lock->get()) {
    //         return response()->json([
    //             'message' => 'The previous request is currently being processed, please wait'
    //         ], 423);
    //     }

    //     try {

    //         $validator = Validator::make($request->all(), [
    //             'items'   => 'required|array|min:1',
    //             'items.*' => 'integer|exists:cart_items,id',
    //         ]);

    //         if ($validator->fails()) {
    //             return response()->json([
    //                 'message' => 'Validation failed',
    //                 'errors'  => $validator->errors()
    //             ], 422);
    //         }

    //         $cart = $user->cart;
    //         if (!$cart) {
    //             return response()->json(['error' => 'Cart not found'], 404);
    //         }

    //         $cartItemIds = collect($request->items);
    //         $cartItems = $cart->cartItems()
    //             ->whereIn('id', $cartItemIds)
    //             ->get();

    //         if ($cartItems->isEmpty() || $cartItems->count() !== $cartItemIds->count()) {
    //             return response()->json(['error' => 'Invalid cart items'], 400);
    //         }

    //         $profile['1_validation_and_cart_ms'] = (hrtime(true) - $stepStart) / 1e6;

    //         $stepStart = hrtime(true);
    //         $this->inventoryService->reserveItems($cartItems);
    //         $profile['2_redis_reservation_ms'] = (hrtime(true) - $stepStart) / 1e6;

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

    //         // throw new \Exception("unexpected error occurred");

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
    //         if (DB::transactionLevel() > 0) DB::rollBack();

    //         $profile['total_php_time_ms'] = (hrtime(true) - $totalStart) / 1e6;
    //         $profile['redis_and_db_time_ms'] = $profile['2_redis_reservation_ms'] ?? 0;

    //         return response()->json([
    //             'error'        => $e->getMessage(),
    //             'product_name' => $e->getProductName(),
    //             'benchmarks'   => $profile
    //         ], 400);

    //     } catch (\Exception $e) {
    //         if (DB::transactionLevel() > 0) DB::rollBack();

    //         // ملاحظة: هنا ستحتاجين لاحقاً لإضافة دالة تعيد المخزون للـ Redis لأن العملية فشلت

    //         $profile['total_php_time_ms'] = (hrtime(true) - $totalStart) / 1e6;

    //         Log::error('Order Profiling (Failed: Exception)', [
    //             'error'   => $e->getMessage(),
    //             'metrics' => $profile
    //         ]);

    //         return response()->json([
    //             'error'      => 'Something went wrong',
    //             'details'    => $e->getMessage(),
    //             'benchmarks' => [
    //                 'total_php_time_ms' => $profile['total_php_time_ms'] ?? 0,
    //                 'redis_and_db_time_ms' => $profile['2_redis_reservation_ms'] ?? 0
    //             ]
    //         ], 500);

    //     } finally {
    //         if (isset($lock)) {
    //             $lock->release();
    //         }
    //     }
    // }


    // After Log
    public function create(Request $request)
    {
        $totalStart = hrtime(true);
        $profile = [];
        $stepStart = hrtime(true);

        $traceId = $request->header('X-Trace-ID', (string) Str::uuid());
        $user = Auth::user();

        $lock = Cache::lock('order-submit-user-' . $user->id, 10);

        if (!$lock->get()) {

            Log::channel('order_locks')->warning('Duplicate Order Click Detected', [
                'user_id'    => $user->id,
                'lock_key'   => 'order-submit-user-' . $user->id,
                'ip_address' => $request->ip(),
                'url'        => $request->fullUrl(),
            ]);

            return response()->json([
                'message' => 'The previous request is currently being processed, please wait'
            ], 423);
        }

        Log::channel('order_locks')->warning('Order Lock Acquired Successfully (First Request)', [
            'user_id'    => $user->id,
            'lock_key'   => 'order-submit-user-' . $user->id,
            'ip_address' => $request->ip(),
            'url'        => $request->fullUrl(),
        ]);

        $formattedItemsLog = 'Unknown';

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

            $formattedItemsLog = $cartItems->map(fn($i) => "P:{$i->product_id}(Qty:{$i->quantity})")->implode(' | ');

            $profile['1_validation_and_cart_ms'] = (hrtime(true) - $stepStart) / 1e6;

            $stepStart = hrtime(true);
            // $this->inventoryService->reserveItems($cartItems);
            $this->inventoryService->reserveItems($cartItems, $traceId);
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

            Log::channel('orders')->info('Order Created Successfully', [
                'trace_id'     => $traceId,
                'order_id'     => $order->id,
                'user_id'      => $user->id,
                'total_amount' => $totalPrice,
                'items'        => $formattedItemsLog
            ]);

            Log::channel('performance')->info("Order Profiling [#{$order->id}]", [
                'trace_id' => $traceId,
                'metrics'  => $profile
            ]);

            return response()->json([
                'message'    => 'Order created successfully',
                'order_id'   => $order->id,
                'benchmarks' => $profile
            ], 201);

        } catch (\App\Exceptions\InsufficientStockException $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();

            $profile['total_php_time_ms'] = (hrtime(true) - $totalStart) / 1e6;
            $profile['redis_and_db_time_ms'] = $profile['2_redis_reservation_ms'] ?? 0;

            Log::channel('inventory')->warning('Stock Reservation Failed', [
                'trace_id'     => $traceId,
                'user_id'      => $user->id,
                'product_name' => $e->getProductName(),
                'attempted'    => $formattedItemsLog,
                'reason'       => $e->getMessage()
            ]);

            return response()->json([
                'error'        => $e->getMessage(),
                'product_name' => $e->getProductName(),
                'benchmarks'   => $profile
            ], 400);

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();

            if (isset($cartItems) && $cartItems->isNotEmpty()) {
                try {
                    foreach ($cartItems as $item) {
                        Redis::incrby("product:{$item->product_id}:stock", $item->quantity);
                    }
                    Log::channel('inventory')->info('Redis Stock Rolled Back directly from Controller (DB Error)');
                } catch (\Exception $redisEx) {
                    Log::channel('inventory')->critical('FATAL: Failed to rollback Redis stock in Controller', [
                        'error' => $redisEx->getMessage()
                    ]);
                }
            }

            $profile['total_php_time_ms'] = (hrtime(true) - $totalStart) / 1e6;

            Log::channel('orders')->error('Order Creation Failed (System Error)', [
                'trace_id' => $traceId,
                'user_id'  => $user->id,
                'items'    => $formattedItemsLog,
                'error'    => $e->getMessage(),
                'metrics'  => $profile
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
