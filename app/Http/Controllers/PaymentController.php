<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Payment;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(env('STRIPE_SECRET'));
    }

    public function createPaymentIntent(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);

        $order = Order::where('user_id', Auth::id())->find($request->order_id);

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $intent = PaymentIntent::create([
            'amount' => intval($order->total_price * 100),
            'currency' => 'usd',
            'metadata' => [
                'order_id' => $order->id,
                'user_id' => Auth::id(),
            ],
        ]);

        $payment = Payment::create([
            'user_id' => Auth::id(),
            'order_id' => $order->id,
            'stripe_payment_intent_id' => $intent->id,
            'amount' => $order->total_price,
            'status' => 'pending',
            'payment_method' => 'stripe',
            'currency' => 'usd',
        ]);

        return response()->json([
            'message' => 'PaymentIntent created',
            'client_secret' => $intent->client_secret,
            'payment_intent_id' => $intent->id,
        ]);
    }

 
 // public function confirmPayment(Request $request)
    // {
    //     $request->validate([
    //         'payment_intent_id' => 'required|string',
    //     ]);

    //     $payment = Payment::where('stripe_payment_intent_id', $request->payment_intent_id)->first();

    //     if (!$payment) {
    //         return response()->json(['message' => 'Payment not found'], 404);
    //     }

    //     $intent = PaymentIntent::retrieve($request->payment_intent_id);

    //     if ($intent->status !== 'succeeded') {
    //         return response()->json(['message' => 'Payment not completed'], 400);
    //     }

    //     $payment->status = 'paid';
    //     $payment->save();

    //     return response()->json([
    //         'message' => 'Payment confirmed successfully',
    //         'payment_status' => 'paid'
    //     ]);
    // }

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


    // public function confirmPayment(Request $request)
    // {
    //     $request->validate(['payment_intent_id' => 'required|string']);
    //     $piId = $request->payment_intent_id;

    //     Stripe::setApiKey(config('services.stripe.secret'));

    //     try {
    //         $intent = PaymentIntent::retrieve($piId);

    //         // لو ما في طريقة دفع، حاول تأكيد اختبارياً
    //         if ($intent->status === 'requires_payment_method') {
    //             $intent = PaymentIntent::confirm($piId, [
    //                 'payment_method' => 'pm_card_visa'
    //             ]);
    //         }

    //         // افحص الخطأ الأخير لو موجود
    //         if (isset($intent->last_payment_error)) {
    //             \Log::warning('PaymentIntent last error', ['pi'=>$piId, 'error'=>$intent->last_payment_error]);
    //         }

    //         if ($intent->status !== 'succeeded') {
    //             return response()->json(['message' => 'Payment not completed', 'status' => $intent->status], 400);
    //         }

    //         $payment = \App\Models\Payment::where('stripe_payment_intent_id', $piId)->first();
    //         if (!$payment) return response()->json(['message'=>'Payment not found'], 404);

    //         if ($payment->status !== 'paid') {
    //             $payment->status = 'paid';
    //             $payment->save();
    //             if ($payment->order) {
    //                 $payment->order->status = 'paid';
    //                 $payment->order->save();
    //             }
    //         }

    //         return response()->json(['message'=>'Payment confirmed successfully','payment_status'=>'paid']);
    //     } catch (\Stripe\Exception\ApiErrorException $e) {
    //         \Log::error('Stripe API error: '.$e->getMessage(), ['pi'=>$piId]);
    //         return response()->json(['message'=>'Stripe API error','details'=>$e->getMessage()], 500);
    //     } catch (\Exception $e) {
    //         \Log::error('ConfirmPayment error: '.$e->getMessage(), ['pi'=>$piId]);
    //         return response()->json(['message'=>'Error confirming payment','details'=>$e->getMessage()], 500);
    //     }
    // }



    public function refund($paymentIntentId)
    {
        $payment = Payment::where('stripe_payment_intent_id', $paymentIntentId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$payment || $payment->status !== 'paid') {
            return false; 
        }

        \Stripe\Refund::create([
            'payment_intent' => $paymentIntentId,
        ]);

        $payment->status = 'canceled';
        $payment->save();

        return true; 
    }
}
