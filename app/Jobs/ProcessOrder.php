<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessOrder implements ShouldQueue
{
    use Queueable;

    protected $order;
    protected $userId;
    protected $scenario;

    /**
     * Create a new job instance.
     */
    public function __construct(Order $order, $userId, $scenario = null)
    {
        $this->order = $order;
        $this->userId = $userId;
        $this->scenario = $scenario;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
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
                'idempotency_key' => 'order_' . $this->order->id
            ]);

            if ($intent->status === 'succeeded') {

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
                });

            } else {

                DB::transaction(function () use ($intent) {

                    foreach ($this->order->orderItems as $item) {

                        $product = Product::lockForUpdate()
                            ->find($item->product_id);

                        if ($product) {
                            $product->increment('stock', $item->quantity);
                        }
                    }

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
            }

        } catch (\Exception $e) {

            logger()->error('Payment Job Failed: ' . $e->getMessage());
        }
    }
}
