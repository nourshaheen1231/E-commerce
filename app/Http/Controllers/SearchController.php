<?php

// namespace App\Http\Controllers;

// use App\Models\Product;
// use Illuminate\Http\Request;

// class SearchController extends Controller
// {
//     public function search(Request $request)
//     {
//         $query = $request->query('q');

//         return Product::search($query)->get();
//     }
// }


namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->query('q');

        if (!$query) {
            return response()->json(['data' => []]);
        }

        $cacheKey = "search_results_" . md5($query);

        $results = Cache::remember($cacheKey, 900, function () use ($query) {
            return Product::search($query)->take(50)->get();
        });

        return response()->json([
            'data' => $results,
            'served_by_worker_port' => request()->server('SERVER_PORT')
        ]);
    }
}
