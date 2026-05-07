<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(env('STRIPE_SECRET'));
    }


    // public function createPaymentIntent(Request $request)
    // {
    //     $request->validate([
    //         'order_id' => 'required|exists:orders,id',
    //         'scenario' => 'nullable|string' // فقط للاختبار
    //     ]);

    //     $order = Order::where('user_id', Auth::id())
    //         ->where('status', 'pending')
    //         ->find($request->order_id);

    //     if (!$order) {
    //         return response()->json(['message' => 'Order not found or not payable'], 404);
    //     }

    //     $paymentMethod = 'pm_card_visa'; // default (success)

    //     if (app()->environment('local', 'testing')) {
    //         switch ($request->scenario) {
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
    //     }

    //     try {
    //         DB::beginTransaction();

    //         $intent = \Stripe\PaymentIntent::create([
    //             'amount' => intval($order->total_price * 100),
    //             'currency' => 'usd',
    //             'payment_method' => $paymentMethod,
    //             'confirm' => true,

    //             'automatic_payment_methods' => [
    //                 'enabled' => true,
    //                 'allow_redirects' => 'never',
    //             ],
    //             'metadata' => [
    //                 'order_id' => $order->id,
    //                 'user_id' => Auth::id(),
    //             ],
    //         ]);

    //         $payment = Payment::create([
    //             'user_id' => Auth::id(),
    //             'order_id' => $order->id,
    //             'stripe_payment_intent_id' => $intent->id,
    //             'amount' => $order->total_price,
    //             'status' => 'pending',
    //             'payment_method' => 'stripe',
    //             'currency' => 'usd',
    //         ]);

    //         if ($intent->status === 'succeeded') {

    //             foreach ($order->orderItems as $item) {
    //                 $product = Product::lockForUpdate()->find($item->product_id);

    //                 if (!$product || $product->stock < $item->quantity) {
    //                     throw new \Exception("Insufficient stock for product ID {$item->product_id}");
    //                 }

    //                 $product->decrement('stock', $item->quantity);
    //             }

    //             $payment->status = 'paid';
    //             $payment->save();

    //             $order->status = 'paid';
    //             $order->save();

    //             DB::commit();

    //             return response()->json([
    //                 'message' => 'Payment successful',
    //                 'order_id' => $order->id,
    //                 'payment_status' => 'paid'
    //             ]);
    //         }

    //         DB::rollBack();

    //         $order->status = 'failed';
    //         $order->save();

    //         return response()->json([
    //             'message' => 'Payment failed',
    //             'status' => $intent->status,
    //             'reason' => $intent->last_payment_error->message ?? null
    //         ], 400);

    //     } catch (\Exception $e) {
    //         DB::rollBack();

    //         return response()->json([
    //             'message' => 'Payment error',
    //             'details' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function createPaymentIntent(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'scenario' => 'nullable|string'
        ]);

        $order = Order::where('user_id', Auth::id())
            ->where('status', 'pending')
            ->with('orderItems')
            ->find($request->order_id);

        if (!$order) {
            return response()->json([
                'message' => 'Order not found or not payable'
            ], 404);
        }

        if ($order->status == 'paid') {
            return response()->json([
                'message' => 'Order already paid'
            ], 400);
        }

        $paymentMethod = 'pm_card_visa';

        if (app()->environment('local', 'testing')) {

            switch ($request->scenario) {

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
        }

        try {

            $intent = \Stripe\PaymentIntent::create([
                'amount' => intval($order->total_price * 100),
                'currency' => 'usd',
                'payment_method' => $paymentMethod,
                'confirm' => true,

                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ],

                'metadata' => [
                    'order_id' => $order->id,
                    'user_id' => Auth::id(),
                ],

            ], [
                'idempotency_key' => 'order_' . $order->id
            ]);

            if ($intent->status === 'succeeded') {

                DB::transaction(function () use ($order, $intent) {

                    Payment::create([
                        'user_id' => Auth::id(),
                        'order_id' => $order->id,
                        'stripe_payment_intent_id' => $intent->id,
                        'amount' => $order->total_price,
                        'status' => 'paid',
                        'payment_method' => 'stripe',
                        'currency' => 'usd',
                    ]);

                    $order->status = 'paid';
                    $order->save();
                });

                return response()->json([
                    'message' => 'Payment successful',
                    'order_id' => $order->id,
                    'payment_status' => 'paid'
                ]);
            }

            DB::transaction(function () use ($order, $intent) {

                foreach ($order->orderItems as $item) {

                    $product = Product::lockForUpdate()
                        ->find($item->product_id);

                    if ($product) {
                        $product->increment('stock', $item->quantity);
                    }
                }

                Payment::create([
                    'user_id' => Auth::id(),
                    'order_id' => $order->id,
                    'stripe_payment_intent_id' => $intent->id ?? null,
                    'amount' => $order->total_price,
                    'status' => 'failed',
                    'payment_method' => 'stripe',
                    'currency' => 'usd',
                ]);

                $order->status = 'failed';
                $order->save();
            });

            return response()->json([
                'message' => 'Payment failed',
                'status' => $intent->status,
                'reason' => $intent->last_payment_error->message ?? null
            ], 400);

        } catch (\Exception $e) {

            return response()->json([
                'message' => 'Payment error',
                'details' => $e->getMessage()
            ], 500);
        }
    }


    public function confirmPayment(Request $request)
    {
        $request->validate([
            'payment_intent_id' => 'required|string',
        ]);

        $piId = $request->payment_intent_id;

        // تأكدي من ضبط مفتاح Stripe
        Stripe::setApiKey(config('services.stripe.secret'));

        // لوج سريع للتأكد من قيمة الـ PI id
        Log::info('ConfirmPayment called', ['pi_id' => $piId]);

        try {
            // استرجاع الـ PaymentIntent من Stripe
            $intent = PaymentIntent::retrieve($piId);

            Log::info('Stripe intent', ['id' => $intent->id, 'status' => $intent->status]);

            if ($intent->status !== 'succeeded') {
                return response()->json([
                    'message' => 'Payment not completed',
                    'status' => $intent->status
                ], 400);
            }

            $payment = \App\Models\Payment::where('stripe_payment_intent_id', $piId)->first();

            if (!$payment) {
                return response()->json(['message' => 'Payment not found'], 404);
            }

            if ($payment->status === 'paid') {
                return response()->json(['message' => 'Already paid', 'payment_status' => 'paid']);
            }

            $payment->status = 'paid';
            $payment->save();

            if ($payment->order) {
                $payment->order->status = 'paid';
                $payment->order->save();
            }

            return response()->json(['message' => 'Payment confirmed successfully', 'payment_status' => 'paid']);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe API error: '.$e->getMessage(), ['pi_id' => $piId]);
            return response()->json(['message' => 'Stripe API error', 'details' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('ConfirmPayment error: '.$e->getMessage(), ['pi_id' => $piId]);
            return response()->json(['message' => 'Error confirming payment', 'details' => $e->getMessage()], 500);
        }
    }


    // public function refund($paymentIntentId)
    // {
    //     $payment = Payment::where('stripe_payment_intent_id', $paymentIntentId)
    //         ->where('user_id', Auth::id())
    //         ->first();

    //     if (!$payment || $payment->status !== 'paid') {
    //         return false; 
    //     }

    //     \Stripe\Refund::create([
    //         'payment_intent' => $paymentIntentId,
    //     ]);

    //     $payment->status = 'canceled';
    //     $payment->save();

    //     return true; 
    // }



    public function refund($paymentIntentId)
    {
        $payment = Payment::where('stripe_payment_intent_id', $paymentIntentId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$payment || $payment->status !== 'paid') {
            return response()->json(['message' => 'Invalid payment'], 400);
        }

        try {
            DB::beginTransaction();

            $refund = \Stripe\Refund::create([
                'payment_intent' => $paymentIntentId,
            ]);

            if ($refund->status !== 'succeeded') {
                throw new \Exception('Refund failed');
            }

            $order = $payment->order;

            foreach ($order->orderItems as $item) {
                $product = Product::lockForUpdate()->find($item->product_id);
                $product->increment('stock', $item->quantity);
            }

            $payment->status = 'refunded';
            $payment->save();

            $order->status = 'canceled';
            $order->save();

            DB::commit();

            return response()->json([
                'message' => 'Refund successful'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Refund failed',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
