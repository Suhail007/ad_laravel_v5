<?php

namespace App\Http\Controllers\Woo;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TestController extends Controller
{
    private $apiUrl = 'https://adfe.phantasm.solutions/wp-json/wc/v3/orders'; // Replace with your WooCommerce endpoint
    private $consumerKey = 'ck_c8dc03022f8f45e6f71552507ef3f36b9d21b272'; // Replace with your Consumer Key
    private $consumerSecret = 'cs_ff377d2ce01a253a56090984036b08c727d945b5'; // Replace with your Consumer Secret

    /**
     * Create an order in WooCommerce.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createOrder(Request $request)
    {
        // Validate request data
        $validated = $request->validate([
            'customer_id' => 'required|integer',
            'line_items' => 'required|array',
            'line_items.*.product_id' => 'required|integer',
            'line_items.*.quantity' => 'required|integer',
            'billing' => 'required|array',
            'shipping' => 'required|array',
        ]);

         $orderData = [
        'payment_method' => 'bacs',
        'payment_method_title' => 'Direct Bank Transfer',
        'set_paid' => true, // Set to false if payment is not done yet
        'billing' => $validated['billing'],
        'shipping' => $validated['shipping'],
        'line_items' => $validated['line_items'],
        'shipping_lines' => $request->input('shipping_lines', []) // Optional
    ];
dd($orderData);
        try {
            // Send request to WooCommerce API
            $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
                ->post($this->apiUrl, $orderData);
            Log::info('response api '.$response);
            // Check if request was successful
            if ($response->successful()) {
                return response()->json($response->json(), 201); // Return created order details
            }

            // Handle errors
            return response()->json([
                'error' => $response->json(),
                'message' => 'Failed to create order',
            ], $response->status());
            
        } catch (\Exception $e) {
            // Handle any exceptions
            return response()->json([
                'error' => $e->getMessage(),
                'message' => 'An error occurred',
            ], 500);
        }
    }
}
