<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessOrder;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;


class PaymentController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(env('STRIPE_SECRET'));
    }



    public function createPaymentIntent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id',
            'scenario' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::firstOrCreate(
            ['id' => $request->order_id],
            [
                'user_id' => Auth::id() ?? 1,
                'status' => 'pending',
                'total_price' => 150.00
            ]
        );

        if (!$order) {
            return response()->json([
                'message' => 'Order not found or not payable'
            ], 404);
        }

        $order->status = 'pending';
        $order->save();

        if ($order->status === 'paid') {
            return response()->json([
                'message' => 'Order already paid'
            ], 400);
        }



        $workerId = ($order->id % 3) + 1;
        $targetQueue = "server_" . $workerId;

        ProcessOrder::dispatch(
            $order,
            Auth::id(),
            $request->scenario
        )->onQueue($targetQueue);


        return response()->json([
            'message' => 'Payment is being processed in background',
        ], 202);
    }



    public function confirmPayment(Request $request)
    {
        $request->validate([
            'payment_intent_id' => 'required|string',
        ]);

        $piId = $request->payment_intent_id;

        Stripe::setApiKey(config('services.stripe.secret'));

        Log::info('ConfirmPayment called', ['pi_id' => $piId]);

        try {
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
            Log::error('Stripe API error: ' . $e->getMessage(), ['pi_id' => $piId]);
            return response()->json(['message' => 'Stripe API error', 'details' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('ConfirmPayment error: ' . $e->getMessage(), ['pi_id' => $piId]);
            return response()->json(['message' => 'Error confirming payment', 'details' => $e->getMessage()], 500);
        }
    }


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
