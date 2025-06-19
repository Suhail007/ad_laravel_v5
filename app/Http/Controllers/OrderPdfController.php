<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrderPdfController extends Controller
{
    public function generateOrderPdf(Request $request, string $orderId)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (!$user) {
                return response()->json(['message' => 'User not authenticated', 'status' => false], 401);
            }
        } catch (\Throwable $th) {
            return response()->json(['status'=>false,'message'=>"login to get this order item list"]);
        }
       
        // Fetch the order and its related data-
        $order = Order::with(['meta', 'items.meta'])->findOrFail($orderId);

        // Extract data for the invoice
        $shippingAddress = $order->meta->where('meta_key', '_shipping_address_1')->first()->meta_value ?? 'N/A';
        $orderDate = $order->post_date;
        $paymentMethod = $order->meta->where('meta_key', '_payment_method_title')->first()->meta_value ?? 'N/A';

        // Prepare order items
        $items = $order->items->where('order_item_type', 'line_item')->map(function ($item) {
            $sku = $item->meta->where('meta_key', '_sku')->first()->meta_value ?? 'N/A';
            $quantity = $item->meta->where('meta_key', '_qty')->first()->meta_value ?? 0;
            $subtotal = $item->meta->where('meta_key', '_line_subtotal')->first()->meta_value ?? 0;
            $total = $item->meta->where('meta_key', '_line_total')->first()->meta_value ?? 0;

            return [
                'name' => $item->order_item_name,
                'sku' => $sku,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
                'total' => $total,
            ];
        });

        // Fetch totals
        $subtotal = $order->meta->where('meta_key', '_order_subtotal')->first()->meta_value ?? 0;
        $shipping = $order->meta->where('meta_key', '_order_shipping')->first()->meta_value ?? 0;
        $tax = $order->meta->where('meta_key', '_order_tax')->first()->meta_value ?? 0;
        $discount = $order->meta->where('meta_key', '_cart_discount')->first()->meta_value ?? 0;
        $total = $order->meta->where('meta_key', '_order_total')->first()->meta_value ?? 0;
        $watermarkNumber= $user->account ?? ' ';

        // Generate the HTML for the PDF
        $html = View::make('pdf.order_invoice', compact(
            'order',
            'shippingAddress',
            'orderDate',
            'paymentMethod',
            'items',
            'subtotal',
            'shipping',
            'tax',
            'discount',
            'total',
            'watermarkNumber'
        ))->render();

        // Generate the PDF
        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Return the generated PDF as a download
        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="order_invoice_' . $orderId . '.pdf"',
        ]);
    }
}
