<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Stripe\Stripe;
use Stripe\PaymentIntent;

class PaymentController extends Controller
{
    // 1) إنشاء PaymentIntent
    public function createPaymentIntent(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->all()], 400);
        }

        $order = Order::where('user_id', $user->id)->find($request->order_id);
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Order already processed'], 400);
        }

        Stripe::setApiKey(env('STRIPE_SECRET'));

        try {
            $intent = PaymentIntent::create([
                'amount' => intval($order->total_price * 100),
                'currency' => 'usd',
                'payment_method_types' => ['card'],
                'metadata' => [
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                ],
            ]);

            Payment::create([
                'order_id' => $order->id,
                'amount' => $order->total_price,
                'payment_method' => 'stripe',
                'status' => 'pending',
                'currency' => 'usd',
                'stripe_payment_intent_id' => $intent->id,
            ]);

            return response()->json([
                'clientSecret' => $intent->client_secret,
                'paymentIntentId' => $intent->id,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Stripe error: ' . $e->getMessage()], 500);
        }
    }

    // 2) تأكيد الدفع
    public function confirmPayment(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'payment_intent_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->all()], 400);
        }

        Stripe::setApiKey(env('STRIPE_SECRET'));

        try {
            $intent = PaymentIntent::retrieve($request->payment_intent_id);

            if ($intent->status !== 'succeeded') {
                return response()->json(['message' => 'Payment not completed'], 400);
            }

            $orderId = $intent->metadata->order_id;
            $order = Order::where('user_id', $user->id)->find($orderId);

            if (!$order) {
                return response()->json(['message' => 'Order not found'], 404);
            }

            $order->status = 'paid';
            $order->save();

            $payment = Payment::where('stripe_payment_intent_id', $intent->id)->first();
            if ($payment) {
                $payment->status = 'paid';
                $payment->save();
            }

            return response()->json([
                'message' => 'Payment confirmed',
                'order_id' => $order->id,
                'status' => 'paid',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Stripe error: ' . $e->getMessage()], 500);
        }
    }

    // 3) Refund بسيط
    public function refund(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->all()], 400);
        }

        $order = Order::where('user_id', $user->id)->find($request->order_id);
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if ($order->status !== 'paid') {
            return response()->json(['message' => 'Only paid orders can be refunded'], 400);
        }

        $payment = Payment::where('order_id', $order->id)->first();
        if (!$payment) {
            return response()->json(['message' => 'Payment record not found'], 404);
        }

        Stripe::setApiKey(env('STRIPE_SECRET'));

        try {
            \Stripe\Refund::create([
                'payment_intent' => $payment->stripe_payment_intent_id,
            ]);

            $order->status = 'canceled';
            $order->save();

            $payment->status = 'canceled';
            $payment->save();

            return response()->json(['message' => 'Order refunded']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Stripe error: ' . $e->getMessage()], 500);
        }
    }
}
