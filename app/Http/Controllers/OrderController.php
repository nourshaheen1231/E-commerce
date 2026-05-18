<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

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

    public function create(Request $request)
    {
        $user = Auth::user();

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

        if ($cartItems->isEmpty()) {
            return response()->json(['error' => 'No valid cart items found'], 400);
        }

        if ($cartItems->count() !== $cartItemIds->count()) {
            return response()->json(['error' => 'Some cart items are invalid'], 400);
        }

        try {
            $order = DB::transaction(function () use ($user, $cartItems) {

                $productIds = $cartItems->pluck('product_id');
                $products = Product::whereIn('id', $productIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

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

                $totalPrice = $cartItems->sum(fn($i) => $i->price * $i->quantity);

                $order = Order::create([
                    'user_id'     => $user->id,
                    'total_price' => $totalPrice,
                ]);

                foreach ($cartItems as $item) {
                    $product = $products->get($item->product_id);

                    // sleep(2);
                    $product->decrement('stock', $item->quantity);

                    OrderItem::create([
                        'order_id'   => $order->id,
                        'product_id' => $item->product_id,
                        'quantity'   => $item->quantity,
                        'price'      => $item->price,
                    ]);
                }

                return $order;
            });

            return response()->json([
                'message'     => 'Order created successfully',
                'order_id'    => $order->id,
                'total_price' => $order->total_price
            ], 201);
        } catch (\App\Exceptions\InsufficientStockException $e) {
            return response()->json([
                'error'        => $e->getMessage(),
                'product_name' => $e->getProductName()
            ], 400);
        } catch (\Exception $e) {
            Log::error('Order creation failed: ' . $e->getMessage());
            return response()->json([
                'error'   => 'Something went wrong',
                'details' => $e->getMessage()
            ], 500);
        }
    }


    public function cancelOrder($id)
    {
        $user = Auth::user();

        // 1) جلب الطلب مع الدفع
        $order = Order::where('user_id', $user->id)
            ->with('payment')
            ->find($id);

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        // 2) منع الإلغاء إذا تم شحنه أو تسليمه
        if (in_array($order->status, ['shipped', 'delivered'])) {
            return response()->json(['message' => 'Order cannot be canceled'], 400);
        }

        // 3) إذا الطلب مدفوع → استدعاء refund
        if ($order->payment && $order->payment->status === 'paid') {

            $paymentController = new PaymentController();

            $refundSuccess = $paymentController->refund(
                $order->payment->stripe_payment_intent_id
            );

            if (!$refundSuccess) {
                return response()->json(['message' => 'Refund failed'], 400);
            }
        }

        // 4) تحديث حالة الطلب
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
