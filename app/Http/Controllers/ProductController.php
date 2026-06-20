<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::all();
        return response()->json($products, 200);
    }

    public function show($id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|integer|exists:products,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }
        $product = Product::find($id);
        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }
        return response()->json($product, 200);
    }

    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:255',
            'description' => 'nullable|string|max:2000',
            'price' => 'required|numeric|min:0.1|max:999999.99',
            'stock' => 'required|integer|min:0|max:1000000',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $product = Product::create($validator->validated());

        return response()->json($product, 201);
    }

    public function update(Request $request, $id)
    {
        $product = Product::find($id);
        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|min:2|max:255',
            'description' => 'sometimes|nullable|string|max:2000',
            'price' => 'sometimes|required|numeric|min:0.1|max:999999.99',
            'stock' => 'sometimes|required|integer|min:0|max:1000000',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $product->update($validator->validated());

        if ($product->wasChanged(['name', 'description', 'price'])) {
            Cache::forget('most_selling_products');
        }

        return response()->json($product, 200);
    }

    public function destroy($id)
    {

        $validator = Validator::make(['id' => $id], [
            'id' => 'required|integer|exists:products,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        Product::destroy($id);

        return response()->json(['message' => "Product with ID: $id deleted"], 200);
    }


    public function showTopselling()
    {
        try {
            #store for 30 minutes
            $topProducts = Cache::remember('most_selling_products', 1800, function () {
                return Product::select('id', 'name', 'description', 'price')
                    ->orderBy('order_count', 'desc')
                    ->take(10)
                    ->get();
            });

            return response()->json([
                'success' => true,
                'message' => 'Top selling products retrieved successfully',
                'data'    => $topProducts
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Failed to retrieve top selling products',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
