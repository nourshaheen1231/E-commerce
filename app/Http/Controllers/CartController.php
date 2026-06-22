<?php

// namespace App\Http\Controllers;

// use App\Models\Cart;
// use App\Models\CartItem;
// use App\Models\Product;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Auth;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Validator;

// class CartController extends Controller
// {
//     // public function index()
//     // {
//     //     $user = Auth::user();

//     //     $cart = $user->cart;

//     //     if (!$cart) {
//     //         return response()->json([], 200);
//     //     }
//     //     DB::enableQueryLog();
//     //     $cartItems = CartItem::with('product')
//     //         ->where('cart_id', $cart->id)
//     //         ->get();
//     //     dd(DB::getQueryLog());
//     //     return response()->json($cartItems, 200);
//     // }

//     public function index()
//     {
//         DB::enableQueryLog();

//         $user = Auth::user();
//         $cart = $user->cart;

//         if (!$cart) {
//             return response()->json([], 200);
//         }

//         $cartItems = CartItem::with('product')->where('cart_id', $cart->id)->get();

//         return response()->json(DB::getQueryLog());
//     }

//     public function add(Request $request)
//     {
//         $user = Auth::user();

//         $validator = Validator::make($request->all(), [
//             'product_id' => 'required|integer|exists:products,id',
//             'quantity'   => 'nullable|integer|min:1|max:1000',
//         ]);

//         if ($validator->fails()) {
//             return response()->json(['error' => $validator->errors()], 422);
//         }

//         $cart = Cart::firstOrCreate(['user_id' => $user->id]);
//         $product = Product::find($request->product_id);

//         $requestedQty = $request->quantity ?? 1;

//         $existingItem = CartItem::where('cart_id', $cart->id)
//             ->where('product_id', $product->id)
//             ->first();

//         if ($existingItem) {
//             return response()->json([
//                 'error' => 'Product already exists in cart'
//             ], 409);
//         }

//         if ($requestedQty > $product->stock) {
//             return response()->json([
//                 'error' => 'Requested quantity exceeds available stock'
//             ], 400);
//         }

//         $cartItem = CartItem::create([
//             'cart_id' => $cart->id,
//             'product_id' => $product->id,
//             'quantity' => $requestedQty,
//             'price' => $product->price * $requestedQty,
//         ]);

//         return response()->json([
//             'message' => 'Product added to cart'
//         ], 201);
//     }

//     public function remove(Request $request)
//     {
//         $user = Auth::user();

//         $validator = Validator::make($request->all(), [
//             'product_id' => 'required|integer|exists:products,id',
//         ]);

//         if ($validator->fails()) {
//             return response()->json(['error' => $validator->errors()], 422);
//         }

//         $cart = $user->cart;

//         if (!$cart) {
//             return response()->json(['error' => 'Cart is empty'], 404);
//         }

//         $cartItem = CartItem::where('cart_id', $cart->id)
//             ->where('product_id', $request->product_id)
//             ->first();

//         if (!$cartItem) {
//             return response()->json(['error' => 'Product not found in cart'], 404);
//         }

//         $cartItem->delete();

//         return response()->json(['message' => 'Product removed from cart'], 200);
//     }

//     public function update(Request $request)
//     {
//         $user = Auth::user();

//         $validator = Validator::make($request->all(), [
//             'product_id' => 'required|integer|exists:products,id',
//             'quantity'   => 'required|integer|min:1|max:1000',
//         ]);

//         if ($validator->fails()) {
//             return response()->json(['error' => $validator->errors()], 422);
//         }

//         $cart = $user->cart;

//         if (!$cart) {
//             return response()->json(['error' => 'Cart is empty'], 404);
//         }

//         $cartItem = CartItem::where('cart_id', $cart->id)
//             ->where('product_id', $request->product_id)
//             ->first();

//         if (!$cartItem) {
//             return response()->json(['error' => 'Product not found in cart'], 404);
//         }

//         $cartItem->quantity = $request->quantity;
//         $cartItem->price = $cartItem->product->price * $request->quantity;
//         $cartItem->save();

//         return response()->json(['message' => 'Cart updated'], 200);
//     }
// }

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class CartController extends Controller
{
    private const CACHE_TTL = 900;

    public function index()
    {
        $user = Auth::user();
        $cacheKey = "cart_user_{$user->id}";

        $cartData = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            $cart = $user->cart;
            if (!$cart) {
                return [];
            }

            return CartItem::with('product:id,name,price,stock')
                ->where('cart_id', $cart->id)
                ->get()
                ->map(fn($item) => [
                    'id'         => $item->id,
                    'product_id' => $item->product_id,
                    'quantity'   => $item->quantity,
                    'price'      => $item->price,
                    'product'    => [
                        'name'  => $item->product?->name,
                        'price' => $item->product?->price,
                        'stock' => $item->product?->stock,
                    ],
                ])
                ->toArray();
        });

        return response()->json(['data' => $cartData], 200);
    }

    public function add(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer|exists:products,id',
            'quantity'   => 'nullable|integer|min:1|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $cart = Cart::firstOrCreate(['user_id' => $user->id]);
        $product = Product::find($request->product_id);
        $requestedQty = $request->quantity ?? 1;

        $existingItem = CartItem::where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->first();

        if ($existingItem) {
            return response()->json(['error' => 'Product already exists in cart'], 409);
        }

        if ($requestedQty > $product->stock) {
            return response()->json(['error' => 'Requested quantity exceeds available stock'], 400);
        }

        CartItem::create([
            'cart_id'    => $cart->id,
            'product_id' => $product->id,
            'quantity'   => $requestedQty,
            'price'      => $product->price * $requestedQty,
        ]);

        Cache::forget("cart_user_{$user->id}");

        return response()->json(['message' => 'Product added to cart'], 201);
    }

    public function remove(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer|exists:products,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $cart = $user->cart;
        if (!$cart) {
            return response()->json(['error' => 'Cart is empty'], 404);
        }

        $cartItem = CartItem::where('cart_id', $cart->id)
            ->where('product_id', $request->product_id)
            ->first();
        if (!$cartItem) {
            return response()->json(['error' => 'Product not found in cart'], 404);
        }

        $cartItem->delete();

        Cache::forget("cart_user_{$user->id}");

        return response()->json(['message' => 'Product removed from cart'], 200);
    }

    public function update(Request $request)
    {
        $user = Auth::user();


        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer|exists:products,id',
            'quantity'   => 'required|integer|min:1|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $cart = $user->cart;
        if (!$cart) {
            return response()->json(['error' => 'Cart is empty'], 404);
        }

        $cartItem = CartItem::where('cart_id', $cart->id)
            ->where('product_id', $request->product_id)
            ->first();
        if (!$cartItem) {
            return response()->json(['error' => 'Product not found in cart'], 404);
        }

        $cartItem->quantity = $request->quantity;
        $cartItem->price = $cartItem->product->price * $request->quantity;
        $cartItem->save();

        Cache::forget("cart_user_{$user->id}");

        return response()->json(['message' => 'Cart updated'], 200);
    }

    public function clear()
    {
        $user = Auth::user();
        $cart = $user->cart;
        if ($cart) {
            $cart->cartItems()->delete();
        }

        Cache::forget("cart_user_{$user->id}");   // ← مهم

        return response()->json(['message' => 'Cart cleared'], 200);
    }
}
