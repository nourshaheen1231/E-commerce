<?php

// namespace App\Jobs;

// use App\Models\Order;
// use App\Models\Payment;
// use App\Models\Product;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Queue\Queueable;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Log;
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

//         Stripe::setApiKey(env('STRIPE_SECRET'));
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
//                 DB::transaction(function () use ($intent) {
//                     Payment::create([
//                         'user_id' => $this->userId,
//                         'order_id' => $this->order->id,
//                         'stripe_payment_intent_id' => $intent->id,
//                         'amount' => $this->order->total_price,
//                         'status' => 'paid',
//                         'payment_method' => 'stripe',
//                         'currency' => 'usd',
//                     ]);

//                     $this->order->status = 'paid';
//                     $this->order->save();

//                     // محاكاة لعمل 3 سيرفرات
//                     // $workerId = ($this->order->id % 3) + 1;
//                     // $targetQueue = "server_" . $workerId;
//                     // المهمة الثانوية :توليد فاتورة
//                     GenerateInvoicePDF::dispatch($this->order);

//                     logger()->info("Order #{$this->order->id}: Payment processed by {$this->queue}. Invoice sent to queue.");
//                 });
//             } else {
//                 $this->handleFailure($intent);
//             }
//         } catch (\Exception $e) {
//             logger()->error('Payment Job Failed: ' . $e->getMessage());
//         }
//     }

//     protected function handleFailure($intent)
//     {
//         DB::transaction(function () use ($intent) {
//             foreach ($this->order->orderItems as $item) {
//                 $product = Product::lockForUpdate()->find($item->product_id);
//                 if ($product) {
//                     $product->increment('stock', $item->quantity);
//                 }
//             }
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
//     }
// }

// // php artisan queue:work --queue=server_1
// // php artisan queue:work --queue=server_2
// // php artisan queue:work --queue=server_3


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
        Log::info("Background Processing: Order #{$this->order->id} is being handled by Worker: {$currentQueue}");

        Stripe::setApiKey(config('services.stripe.secret') ?? env('STRIPE_SECRET'));
        $paymentMethod = 'pm_card_visa';

        switch ($this->scenario) {
            case 'fail':
                $paymentMethod = 'pm_card_chargeDeclined';
                break;
            case 'insufficient':
                $paymentMethod = 'pm_card_insufficientFunds';
                break;
            case 'auth':
                $paymentMethod = 'pm_card_authenticationRequired';
                break;
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
                'idempotency_key' => 'order_' . $this->order->id . '_' . time()
            ]);

            if ($intent->status === 'succeeded') {
                $this->handleSuccess($intent);
            } else {
                $this->handleFailure($intent);
            }
        } catch (\Exception $e) {
            Log::error('Payment Job Failed: ' . $e->getMessage());
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

            // new
            foreach ($this->order->orderItems as $item) {
                $product = Product::find($item->product_id);
                if ($product) {
                    $product->increment('order_count', $item->quantity);
                }
            }

            foreach ($this->order->orderItems as $item) {
                Product::where('id', $item->product_id)
                       ->decrement('stock', $item->quantity);
            }

            \App\Jobs\GenerateInvoicePDF::dispatch($this->order);

            Log::info("Order #{$this->order->id}: Payment processed, MySQL stock updated. Invoice queued.");
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

        });

        $this->releaseRedisStock();
    }


    protected function releaseRedisStock(): void
    {
        try {
            foreach ($this->order->orderItems as $item) {
                Redis::incrby("product:{$item->product_id}:stock", $item->quantity);
            }
            Log::info("Order #{$this->order->id}: Payment failed. Released reserved stock back to Redis.");
        } catch (\Exception $e) {
            Log::error("Failed to release Redis stock for Order #{$this->order->id}: " . $e->getMessage());
        }
    }
}
