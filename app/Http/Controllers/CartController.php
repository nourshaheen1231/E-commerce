<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        $cart = $user->cart;

        if (!$cart) {
            return response()->json([]);
        }

        $cartItems = CartItem::with('product')
            ->where('cart_id', $cart->id)
            ->get();

        return response()->json($cartItems, 200);
    }

    public function add(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        $cart = Cart::where('user_id', $user->id)->first();
        if (!$cart) {
            $cart = Cart::create(['user_id' => $user->id]);
        }
        $cartItem = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $request->input('product_id'),
            'quantity' => $request->input('quantity', 1),
        ]);

        $price = $cartItem->product->price;
        $cartItem->price = $price * $cartItem->quantity;
        $cartItem->save();


        return response()->json(['message' => 'Product added to cart'], 201);
    }

    public function remove(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $cart = $user->cart;

        $cartItem = CartItem::where('cart_id', $cart->id)->where('product_id', $request->input('product_id'))->first();

        if (!$cartItem) {
            return response()->json(['error' => 'Product not found in cart'], 404);
        }

        $cartItem->delete();

        return response()->json(['message' => 'Product removed from cart'], 200);
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $cart = $user->cart;

        $cartItem = CartItem::where('cart_id', $cart->id)->where('product_id', $request->input('product_id'))->first();

        if (!$cartItem) {
            return response()->json(['error' => 'Product not found in cart'], 404);
        }

        $cartItem->quantity = $request->input('quantity', $cartItem->quantity);
        $price = $cartItem->product->price;
        $cartItem->price = $price * $cartItem->quantity;
        $cartItem->save();

        return response()->json(['message' => 'Cart updated'], 200);
    }
}
