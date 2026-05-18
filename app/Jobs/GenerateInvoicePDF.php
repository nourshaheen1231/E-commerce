<?php

namespace App\Jobs;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenerateInvoicePDF implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function handle()
    {

        logger()->info("[PDF Job] Started generating invoice for Order #{$this->order->id}");

        sleep(5);

        $order = $this->order->load(['orderItems.product', 'user']);

        $data = [
            'title' => ' invoice number ' . $this->order->id,
            'id'    => $order->id,
            'date'  => $order->created_at->format('d/m/Y'),
            'name'  => $order->user->name,
            'items' => $order->orderItems,
            'total' => $order->total_price,
        ];

        $pdf = Pdf::loadView('invoices.order_pdf', $data);
        $path = 'invoices/invoice_' . $order->id . '.pdf';
        if (!Storage::exists('public/invoices')) {
            Storage::makeDirectory('public/invoices');
        }

        Storage::disk('public')->put('invoices/invoice_' . $this->order->id . '.pdf', $pdf->output());
        $order->invoice_path = $path;
        $order->save();

        $this->order->update([
            'processed_by' => $this->queue
        ]);
        logger()->info("PDF Generated for Order #{$order->id}");
    }
}
