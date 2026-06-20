<?php

// namespace App\Jobs;

// use App\Models\Order;
// use App\Models\Payment;
// use App\Models\Product;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Queue\Queueable;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Redis;
// use Stripe\Stripe;

// class ProcessOrder implements ShouldQueue
// {
//     use Queueable;

//     protected $order;
//     protected $userId;
//     protected $scenario;

//     public function __construct(Order $order, $userId, $scenario = null)
//     {
//         $this->order = $order;
//         $this->userId = $userId;
//         $this->scenario = $scenario;
//     }

//     public function handle(): void
//     {
//         $this->order->update([
//             'processed_by' => $this->queue ?? 'default'
//         ]);

//         $currentQueue = $this->queue ?? 'default';
//         Log::info("Background Processing: Order #{$this->order->id} is being handled by Worker: {$currentQueue}");

//         Stripe::setApiKey(config('services.stripe.secret') ?? env('STRIPE_SECRET'));
//         $paymentMethod = 'pm_card_visa';

//         switch ($this->scenario) {
//             case 'fail':
//                 $paymentMethod = 'pm_card_chargeDeclined';
//                 break;
//             case 'insufficient':
//                 $paymentMethod = 'pm_card_insufficientFunds';
//                 break;
//             case 'auth':
//                 $paymentMethod = 'pm_card_authenticationRequired';
//                 break;
//         }

//         try {
//             $intent = \Stripe\PaymentIntent::create([
//                 'amount' => intval($this->order->total_price * 100),
//                 'currency' => 'usd',
//                 'payment_method' => $paymentMethod,
//                 'confirm' => true,
//                 'automatic_payment_methods' => [
//                     'enabled' => true,
//                     'allow_redirects' => 'never',
//                 ],
//                 'metadata' => [
//                     'order_id' => $this->order->id,
//                     'user_id' => $this->userId,
//                 ],
//             ], [
//                 'idempotency_key' => 'order_' . $this->order->id . '_' . time()
//             ]);

//             if ($intent->status === 'succeeded') {
//                 $this->handleSuccess($intent);
//             } else {
//                 $this->handleFailure($intent);
//             }
//         } catch (\Exception $e) {
//             Log::error('Payment Job Failed: ' . $e->getMessage());
//             $this->handleFailure(null);
//         }
//     }


//     protected function handleSuccess($intent): void
//     {
//         DB::transaction(function () use ($intent) {
//             Payment::create([
//                 'user_id' => $this->userId,
//                 'order_id' => $this->order->id,
//                 'stripe_payment_intent_id' => $intent->id,
//                 'amount' => $this->order->total_price,
//                 'status' => 'paid',
//                 'payment_method' => 'stripe',
//                 'currency' => 'usd',
//             ]);

//             $this->order->status = 'paid';
//             $this->order->save();

//             // new
//             foreach ($this->order->orderItems as $item) {
//                 $product = Product::find($item->product_id);
//                 if ($product) {
//                     $product->increment('order_count', $item->quantity);
//                 }
//             }

//             foreach ($this->order->orderItems as $item) {
//                 Product::where('id', $item->product_id)
//                        ->decrement('stock', $item->quantity);
//             }

//             \App\Jobs\GenerateInvoicePDF::dispatch($this->order);

//             Log::info("Order #{$this->order->id}: Payment processed, MySQL stock updated. Invoice queued.");
//         });
//     }


//     protected function handleFailure($intent): void
//     {
//         DB::transaction(function () use ($intent) {
//             Payment::create([
//                 'user_id' => $this->userId,
//                 'order_id' => $this->order->id,
//                 'stripe_payment_intent_id' => $intent->id ?? null,
//                 'amount' => $this->order->total_price,
//                 'status' => 'failed',
//                 'payment_method' => 'stripe',
//                 'currency' => 'usd',
//             ]);

//             $this->order->status = 'failed';
//             $this->order->save();

//         });

//         $this->releaseRedisStock();
//     }


//     protected function releaseRedisStock(): void
//     {
//         try {
//             foreach ($this->order->orderItems as $item) {
//                 Redis::incrby("product:{$item->product_id}:stock", $item->quantity);
//             }
//             Log::info("Order #{$this->order->id}: Payment failed. Released reserved stock back to Redis.");
//         } catch (\Exception $e) {
//             Log::error("Failed to release Redis stock for Order #{$this->order->id}: " . $e->getMessage());
//         }
//     }
// }


namespace App\Jobs;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Stripe\Stripe;

class ProcessOrder implements ShouldQueue
{
    use Queueable;

    protected $order;
    protected $userId;
    protected $scenario;

    public function __construct(Order $order, $userId, $scenario = null)
    {
        $this->order = $order;
        $this->userId = $userId;
        $this->scenario = $scenario;
    }

    public function handle(): void
    {
        $this->order->update([
            'processed_by' => $this->queue ?? 'default'
        ]);

        $currentQueue = $this->queue ?? 'default';

        Log::channel('orders')->info('Background Job Started', [
            'order_id' => $this->order->id,
            'worker'   => $currentQueue,
            'scenario' => $this->scenario
        ]);

        Stripe::setApiKey(config('services.stripe.secret') ?? env('STRIPE_SECRET'));
        $paymentMethod = 'pm_card_visa';

        switch ($this->scenario) {
            case 'fail':         $paymentMethod = 'pm_card_chargeDeclined'; break;
            case 'insufficient': $paymentMethod = 'pm_card_insufficientFunds'; break;
            case 'auth':         $paymentMethod = 'pm_card_authenticationRequired'; break;
        }

        try {
            $intent = \Stripe\PaymentIntent::create([
                'amount' => intval($this->order->total_price * 100),
                'currency' => 'usd',
                'payment_method' => $paymentMethod,
                'confirm' => true,
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ],
                'metadata' => [
                    'order_id' => $this->order->id,
                    'user_id' => $this->userId,
                ],
            ], [
                // 'idempotency_key' => 'order_' . $this->order->id . '_' . time()
                'idempotency_key' => 'order_' . $this->order->id
            ]);

            if ($intent->status === 'succeeded') {
                Log::channel('payments')->info('Stripe Payment Intent Succeeded', [
                    'order_id'  => $this->order->id,
                    'intent_id' => $intent->id,
                    'amount'    => $this->order->total_price
                ]);

                $this->handleSuccess($intent);
            } else {
                Log::channel('payments')->warning('Stripe Payment Intent Declined', [
                    'order_id'  => $this->order->id,
                    'intent_id' => $intent->id,
                    'status'    => $intent->status
                ]);

                $this->handleFailure($intent);
            }
        } catch (\Exception $e) {
            Log::channel('payments')->error('Stripe Integration Failed (Exception)', [
                'order_id' => $this->order->id,
                'user_id'  => $this->userId,
                'error'    => $e->getMessage()
            ]);

            $this->handleFailure(null);
        }
    }

    protected function handleSuccess($intent): void
    {
        DB::transaction(function () use ($intent) {
            Payment::create([
                'user_id' => $this->userId,
                'order_id' => $this->order->id,
                'stripe_payment_intent_id' => $intent->id,
                'amount' => $this->order->total_price,
                'status' => 'paid',
                'payment_method' => 'stripe',
                'currency' => 'usd',
            ]);

            $this->order->status = 'paid';
            $this->order->save();

            $updatedProductsLog = [];
            foreach ($this->order->orderItems as $item) {
                Product::where('id', $item->product_id)->update([
                    'order_count' => DB::raw("order_count + {$item->quantity}"),
                    'stock'       => DB::raw("stock - {$item->quantity}")
                ]);
                $updatedProductsLog[] = "P:{$item->product_id}(Qty:{$item->quantity})";
            }

            // \App\Jobs\GenerateInvoicePDF::dispatch($this->order);
            \App\Jobs\GenerateInvoicePDF::dispatch($this->order)->afterCommit();

            Log::channel('orders')->info('Order Finalized and Paid', [
                'order_id' => $this->order->id
            ]);

            Log::channel('inventory')->info('MySQL Stock Deducted (Sync with Redis)', [
                'order_id' => $this->order->id,
                'items'    => implode(', ', $updatedProductsLog)
            ]);
        });
    }

    protected function handleFailure($intent): void
    {
        DB::transaction(function () use ($intent) {
            Payment::create([
                'user_id' => $this->userId,
                'order_id' => $this->order->id,
                'stripe_payment_intent_id' => $intent->id ?? null,
                'amount' => $this->order->total_price,
                'status' => 'failed',
                'payment_method' => 'stripe',
                'currency' => 'usd',
            ]);

            $this->order->status = 'failed';
            $this->order->save();

            Log::channel('orders')->warning('Order Marked as Failed', [
                'order_id' => $this->order->id
            ]);
        });

        $this->releaseRedisStock();
    }

    protected function releaseRedisStock(): void
    {
        try {
            $releasedItems = [];
            foreach ($this->order->orderItems as $item) {
                Redis::incrby("product:{$item->product_id}:stock", $item->quantity);
                $releasedItems[] = "P:{$item->product_id}(Qty:{$item->quantity})";
            }

            Log::channel('inventory')->info('Redis Stock Released (Rollback Complete)', [
                'order_id' => $this->order->id,
                'items'    => implode(', ', $releasedItems)
            ]);

        } catch (\Exception $e) {
            Log::channel('inventory')->critical('FATAL: Failed to release Redis stock (Inventory Leakage Risk)', [
                'order_id' => $this->order->id,
                'error'    => $e->getMessage()
            ]);
        }
    }
}
