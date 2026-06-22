<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class OrderController extends Controller
{

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



    public function create(Request $request)
    {
        $totalStart = hrtime(true);
        $profile = [];

        $user    = Auth::user();
        $traceId = $request->header('X-Trace-ID', (string) Str::uuid());

        $stepStart = hrtime(true);
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
        $profile['0_app_lock_acquire_ms'] = (hrtime(true) - $stepStart) / 1e6;

        Log::channel('order_locks')->info('Order Lock Acquired (First Request)', [
            'user_id'  => $user->id,
            'lock_key' => 'order-submit-user-' . $user->id,
        ]);

        $audit = [];

        try {
            $stepStart = hrtime(true);

            $validator = Validator::make($request->all(), [
                'items'   => 'required|array|min:1',
                'items.*' => 'integer|exists:cart_items,id',
            ]);
            if ($validator->fails()) {
                return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
            }

            $cart = $user->cart;
            if (!$cart) {
                return response()->json(['error' => 'Cart not found'], 404);
            }

            $cartItemIds = collect($request->items);
            $cartItems   = $cart->cartItems()->whereIn('id', $cartItemIds)->get();

            if ($cartItems->isEmpty()) {
                return response()->json(['error' => 'No valid cart items found'], 400);
            }
            if ($cartItems->count() !== $cartItemIds->count()) {
                return response()->json(['error' => 'Some cart items are invalid'], 400);
            }

            $profile['1_validation_and_cart_ms'] = (hrtime(true) - $stepStart) / 1e6;

            $order = DB::transaction(function () use ($user, $cartItems, &$profile, &$audit) {

                $productIds = $cartItems->pluck('product_id');
                $stepStart = hrtime(true);
                $products = Product::whereIn('id', $productIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $profile['2_row_lock_wait_ms'] = (hrtime(true) - $stepStart) / 1e6;

                $stepStart = hrtime(true);
                foreach ($cartItems as $item) {
                    $product = $products->get($item->product_id);
                    if (!$product) {
                        throw new \Exception("Product not found for id {$item->product_id}");
                    }
                    if ($product->stock < $item->quantity) {
                        throw new \App\Exceptions\InsufficientStockException(
                            "Requested quantity exceeds available stock",
                            $product->name
                        );
                    }
                }
                $profile['3_stock_check_ms'] = (hrtime(true) - $stepStart) / 1e6;

                $stepStart = hrtime(true);
                $totalPrice = $cartItems->sum(fn($i) => $i->price * $i->quantity);

                $order = Order::create([
                    'user_id'     => $user->id,
                    'total_price' => $totalPrice,
                ]);

                foreach ($cartItems as $item) {
                    $product = $products->get($item->product_id);


                    $before = $product->stock;
                    $product->decrement('stock', $item->quantity);
                    $after  = $product->stock;
                    $audit[] = [
                        'product_id' => $product->id,
                        'name'       => $product->name,
                        'qty'        => $item->quantity,
                        'before'     => $before,
                        'after'      => $after,
                    ];

                    OrderItem::create([
                        'order_id'   => $order->id,
                        'product_id' => $item->product_id,
                        'quantity'   => $item->quantity,
                        'price'      => $item->price,
                    ]);
                }
                $profile['4_order_write_ms'] = (hrtime(true) - $stepStart) / 1e6;

                $cart = $user->cart;
                if (!$cart) {
                    return response()->json(['error' => 'Cart not found'], 404);
                }
                $cart->cartItems()->delete();
                // throw new \Exception("unexpected error occurred");

                return $order;
            });

            foreach ($audit as $row) {
                Log::channel('inventory')->info('Stock Movement', [
                    'trace_id'   => $traceId,
                    'order_id'   => $order->id,
                    'user_id'    => $user->id,
                    'product_id' => $row['product_id'],
                    'name'       => $row['name'],
                    'qty'        => $row['qty'],
                    'before'     => $row['before'],
                    'after'      => $row['after'],
                    'source'     => 'controller',
                ]);
            }
            Cache::forget("cart_user_{$user->id}");

            $stepStart = hrtime(true);
            ProcessOrder::dispatch($order, $user->id, $request->scenario)->afterCommit();
            $profile['5_dispatch_ms'] = (hrtime(true) - $stepStart) / 1e6;

            $profile['total_php_time_ms'] = (hrtime(true) - $totalStart) / 1e6;

            Log::channel('performance')->info("Order Profiling [#{$order->id}]", [
                'trace_id' => $traceId,
                'metrics'  => $profile,
            ]);

            Log::channel('orders')->info('Order Created Successfully', [
                'trace_id'     => $traceId,
                'order_id'     => $order->id,
                'user_id'      => $user->id,
                'total_price'  => $order->total_price,
                'items_count'  => $cartItems->count(),
            ]);

            return response()->json([
                'message'     => 'Order created successfully',
                'order_id'    => $order->id,
                'total_price' => $order->total_price,
                'benchmarks'  => $profile,
            ], 201);
        } catch (\App\Exceptions\InsufficientStockException $e) {
            $profile['total_php_time_ms'] = (hrtime(true) - $totalStart) / 1e6;

            Log::channel('inventory')->warning('Stock Reservation Failed (Out of Stock)', [
                'trace_id'     => $traceId,
                'user_id'      => $user->id,
                'product_name' => $e->getProductName(),
            ]);


            return response()->json([
                'error'        => $e->getMessage(),
                'product_name' => $e->getProductName(),
                'benchmarks'   => $profile,
            ], 400);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '40001') {
                Log::channel('orders')->warning('Deadlock detected (transaction rolled back)', [
                    'trace_id' => $traceId,
                    'user_id'  => $user->id,
                ]);
                $profile['total_php_time_ms'] = (hrtime(true) - $totalStart) / 1e6;
                return response()->json([
                    'error'      => 'Conflict, please retry',
                    'benchmarks' => $profile,
                ], 409);
            }


            Log::channel('orders')->error('Order Creation Failed (DB Error)', [
                'trace_id' => $traceId,
                'user_id'  => $user->id,
                'error'    => $e->getMessage(),
            ]);
            $profile['total_php_time_ms'] = (hrtime(true) - $totalStart) / 1e6;
            return response()->json([
                'error'      => 'Something went wrong',
                'details'    => $e->getMessage(),
                'benchmarks' => $profile,
            ], 500);
        } catch (\Exception $e) {
            Log::channel('orders')->error('Order Creation Failed (System Error)', [
                'trace_id' => $traceId,
                'user_id'  => $user->id,
                'error'    => $e->getMessage(),
            ]);
            $profile['total_php_time_ms'] = (hrtime(true) - $totalStart) / 1e6;
            return response()->json([
                'error'      => 'Something went wrong',
                'details'    => $e->getMessage(),
                'benchmarks' => $profile,
            ], 500);
        } finally {
            $lock->release();
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
