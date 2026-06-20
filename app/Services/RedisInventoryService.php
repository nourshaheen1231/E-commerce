<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use App\Models\Product;
use App\Exceptions\InsufficientStockException;
use Illuminate\Support\Facades\Log;

class RedisInventoryService
{
    public function reserveItems($cartItems): void
    {
        $reserved = [];

        try {
            foreach ($cartItems as $item) {
                $this->decrementAtomicStock($item->product_id, $item->quantity);
                $reserved[] = ['id' => $item->product_id, 'qty' => $item->quantity];
            }
        } catch (InsufficientStockException $e) {
            foreach ($reserved as $res) {
                Redis::incrby("product:{$res['id']}:stock", $res['qty']);
            }
            throw $e;
        }
    }


    private function decrementAtomicStock(int $productId, int $quantity): int
    {

        $start = hrtime(true);
        $redisKey = "product:{$productId}:stock";

        $script = "
            local stock = tonumber(redis.call('GET', KEYS[1]))
            if stock and stock >= tonumber(ARGV[1]) then
                return redis.call('DECRBY', KEYS[1], ARGV[1])
            else
                return -1
            end
        ";

        $newStock = Redis::eval($script, 1, $redisKey, $quantity);

        if ($newStock === -1) {
            $productName = Product::where('id', $productId)->value('name') ?? "ID: {$productId}";
            throw new InsufficientStockException("Requested quantity exceeds available stock", $productName);
        }

        $elapsed = (hrtime(true) - $start) / 1e6;

        Log::info('redis_eval_ms', [
            'product_id' => $productId,
            'time_ms' => $elapsed,
        ]);

        return $newStock;
    }
}
// \Illuminate\Support\Facades\Redis::set('product:1:stock', 30);
