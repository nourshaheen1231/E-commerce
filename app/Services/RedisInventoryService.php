<?php

// namespace App\Services;

// use Illuminate\Support\Facades\Redis;
// use App\Models\Product;
// use App\Exceptions\InsufficientStockException;
// use Illuminate\Support\Facades\Log;

// class RedisInventoryService
// {
//     public function reserveItems($cartItems): void
//     {
//         $reserved = [];

//         try {
//             foreach ($cartItems as $item) {
//                 $this->decrementAtomicStock($item->product_id, $item->quantity);
//                 $reserved[] = ['id' => $item->product_id, 'qty' => $item->quantity];
//             }
//         } catch (InsufficientStockException $e) {
//             foreach ($reserved as $res) {
//                 Redis::incrby("product:{$res['id']}:stock", $res['qty']);
//             }
//             throw $e;
//         }
//     }


//     private function decrementAtomicStock(int $productId, int $quantity): int
//     {

//         $start = hrtime(true);
//         $redisKey = "product:{$productId}:stock";

//         $script = "
//             local stock = tonumber(redis.call('GET', KEYS[1]))
//             if stock and stock >= tonumber(ARGV[1]) then
//                 return redis.call('DECRBY', KEYS[1], ARGV[1])
//             else
//                 return -1
//             end
//         ";

//         $newStock = Redis::eval($script, 1, $redisKey, $quantity);

//         if ($newStock === -1) {
//             $productName = Product::where('id', $productId)->value('name') ?? "ID: {$productId}";
//             throw new InsufficientStockException("Requested quantity exceeds available stock", $productName);
//         }

//         $elapsed = (hrtime(true) - $start) / 1e6;

//         Log::info('redis_eval_ms', [
//             'product_id' => $productId,
//             'time_ms' => $elapsed,
//         ]);

//         return $newStock;
//     }
// }
// // \Illuminate\Support\Facades\Redis::set('product:1:stock', 30);


namespace App\Services;

use Illuminate\Support\Facades\Redis;
use App\Models\Product;
use App\Exceptions\InsufficientStockException;
use Illuminate\Support\Facades\Log;

class RedisInventoryService
{
    /**
     * @param \Illuminate\Support\Collection $cartItems
     * @param string|null $traceId
     * @throws InsufficientStockException
     */
    public function reserveItems($cartItems, ?string $traceId = null): void
    {
        $start = hrtime(true);

        $keys = [];
        $args = [];
        $itemMap = [];

        foreach ($cartItems as $item) {
            $keys[] = "product:{$item->product_id}:stock";
            $args[] = $item->quantity;

            $itemMap["product:{$item->product_id}:stock"] = $item;
        }

        $script = "
            for i, key in ipairs(KEYS) do
                local required = tonumber(ARGV[i])
                local current_stock = tonumber(redis.call('GET', key))

                if not current_stock or current_stock < required then
                    return {-1, key}
                end
            end

            for i, key in ipairs(KEYS) do
                redis.call('DECRBY', key, ARGV[i])
            end

            return {1, 'OK'}
        ";

        $result = Redis::eval($script, count($keys), ...array_merge($keys, $args));

        $elapsed = (hrtime(true) - $start) / 1e6;

        if (is_array($result) && $result[0] === -1) {
            $failedKey = $result[1];
            $failedItem = $itemMap[$failedKey] ?? null;

            $productName = $failedItem && $failedItem->product ? $failedItem->product->name : "ID: " . str_replace(['product:', ':stock'], '', $failedKey);

            Log::channel('inventory')->warning('Atomic Bulk Reservation Failed (Out of Stock)', [
                'trace_id'     => $traceId,
                'failed_key'   => $failedKey,
                'product_name' => $productName,
                'time_ms'      => $elapsed
            ]);

            throw new InsufficientStockException("Requested quantity exceeds available stock", $productName);
        }

        Log::channel('inventory')->info('Atomic Bulk Reservation Succeeded', [
            'trace_id'   => $traceId,
            'items_count'=> count($keys),
            'time_ms'    => $elapsed
        ]);
    }
}
