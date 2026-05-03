<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function orderHistory()
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $orders = Order::where('user_id', $user->id)->get();

        return response()->json($orders, 200);
    }

    public function orderDetails(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $order = Order::where('user_id', $user->id)->where('id', $request->id)->first();
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $orderItems = OrderItem::with('product')->where('order_id', $order->id)->get();

        return response()->json($orderItems, 200);
    }

    public function create(Request $request)
    { // To Do (Check the Quantity of the products)
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'error' => 'Unauthorized'
            ], 401);
        }

        $request->validate([
            'items' => 'required|array|min:1'
        ]);

        $cart = $user->cart;

        if (!$cart) {
            return response()->json([
                'error' => 'Cart not found'
            ], 404);
        }

        $cartItems = $cart->items()
            ->whereIn('id', $request->items)
            ->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'error' => 'No valid cart items found'
            ], 400);
        }

        if ($cartItems->count() !== count($request->items)) {
            return response()->json([
                'error' => 'Some cart items are invalid'
            ], 400);
        }

        DB::beginTransaction();

        try {

            $order = Order::create([
                'user_id' => $user->id,
                'total_price' => $cartItems->sum(fn($i) => $i->price * $i->quantity),
            ]);

            foreach ($cartItems as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                ]);
            }

            $cart->items()
                ->whereIn('id', $request->items)
                ->delete();

            DB::commit();

            return response()->json([
                'message' => 'Order created successfully',
                'order_id' => $order->id,
                'total_price' => $order->total_price
            ], 201);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'error' => 'Something went wrong',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function updateOrderStatus(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'error' => 'Unauthorized'
            ], 401);
        }
    }
}
