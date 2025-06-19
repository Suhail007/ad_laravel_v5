<?php

namespace App\Http\Controllers\Checkout;

use App\Http\Controllers\Controller;
use App\Jobs\BufferJob;
use App\Jobs\SendOrderConfirmationEmail;
use App\Models\Buffer;
use App\Models\Cart;
use App\Models\Checkout;
use App\Models\DiscountRule;
use App\Models\OrderItemMeta;
use App\Models\OrderMeta;
use App\Models\ProductMeta;
use App\Models\User;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpParser\Node\Stmt\TryCatch;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProcessOrderController extends Controller
{
    private $security_key;
    public function __construct()
    {
        $this->security_key = config('services.nmi.security');
    }

    private function validateBilling($billingInformation)
    {
        $validBillingKeys = [
            "first_name",
            "last_name",
            "company",
            "address1",
            "address2",
            "city",
            "state",
            "zip",
            "country",
            "phone",
            "fax",
            "email"
        ];

        foreach ($billingInformation as $key => $value) {
            if (!in_array($key, $validBillingKeys)) {
                throw new Exception("Invalid key provided in billingInformation. '{$key}' is not a valid billing parameter.");
            }
        }
    }


    private function validateShipping($shippingInformation)
    {
        $validShippingKeys = [
            "shipping_first_name",
            "shipping_last_name",
            "shipping_company",
            "shipping_address1",
            "address2",
            "shipping_city",
            "shipping_state",
            "shipping_zip",
            "shipping_country",
            "shipping_email"
        ];

        foreach ($shippingInformation as $key => $value) {
            if (!in_array($key, $validShippingKeys)) {
                throw new Exception("Invalid key provided in shippingInformation. '{$key}' is not a valid shipping parameter.");
            }
        }
    }

    private function doSale($amount, $payment_token, $billing, $shipping)
    {
        $requestOptions = [
            'type' => 'sale',
            'amount' => $amount,
            'payment_token' => $payment_token
        ];
        $requestOptions = array_merge($requestOptions, $billing, $shipping);

        return $requestOptions;
    }

    private function _doRequest($postData)
    {
        $hostName = "secure.nmi.com";
        $path = "/api/transact.php";
        $client = new Client();

        $postData['security_key'] = config('services.nmi.security');
        $postUrl = "https://{$hostName}{$path}";

        try {
            $response = $client->post($postUrl, [
                'form_params' => $postData,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ]
            ]);

            parse_str($response->getBody(), $responseArray);

            $parsedResponseCode = (int)$responseArray['response_code'];
            $status = in_array($parsedResponseCode, [100, 200]);

            $paydata = [
                'status' => $status,
                'date' => $response->getHeaderLine('Date'),
                'responsetext' => $responseArray['responsetext'],
                'authcode' => $responseArray['authcode'] ?? '',
                'transactionid' => $responseArray['transactionid'] ?? 'failed',
                'avsresponse' => $responseArray['avsresponse'] ?? 'N',
                'cvvresponse' => $responseArray['cvvresponse'] ?? 'N',
                'description' => $response->getBody()->getContents(),
                'response_code' => $parsedResponseCode,
                'type' => $responseArray['type'] ?? ''
            ];

            return $paydata;
        } catch (Exception $e) {
            throw new Exception("Error: " . $e->getMessage());
        }
    }


    // private function calTotal($user_id,$shippingLines){
    //     $total = 0;
    //     $orderData = Checkout::where('user_id', $user_id)->first();
    //             $isVape = false;
    //             $order_tax = 0;
    //             $ordertotalQTY = 0;
    //             $productnames = [];
    //             $is_free = false;
    //             $percentageDiscount = 0;
    //             $cartDiscount = 0;
    //             $couponIDs = [];
    //             $isPercentageCoupone = false;
    //             foreach ($orderData['extra'] as $item) {
    //                 $ordertotalQTY += $item['quantity'];
    //                 $subtotal = $item['product_price'];

    //                 $productnames[] = $item['product_name'];

    //                 if ($item['isVape'] == true) {
    //                     $order_tax += $item['quantity'] * $item['taxPerUnit'];
    //                     $order_tax = round($order_tax, 1);
    //                     $isVape = true;
    //                 } else {
    //                     $subtotal = $subtotal + ($item['taxPerUnit'] ?? 0);
    //                 }
    //                 try {
    //                     if (isset($item['discount_amt']) && $item['discount_amt']) {
    //                         $cartDiscount += $item['discount_amt'];

    //                         $couponIDs[] = $item['applicable_rules'][0]['rule_id'];
    //                         $isPercentageCoupone = true;
    //                     }
    //                 } catch (\Throwable $th) {
    //                 }

    //                 $float1 = $item['quantity'] * $subtotal;
    //                 $total += round($float1, 2);
    //             }

    //             //cart discount 
    //             $cartDiscountTax = 0;
    //             if ($cartDiscount > 0 && $isVape) {
    //                 $cartDiscountTax = $cartDiscount * 0.15;
    //                 $cartDiscountTax = round($cartDiscountTax, 2);
    //             }
    //             $cartDiscount = round($cartDiscount, 2);
    //             $total = $total - $cartDiscount + $order_tax;

    //             $subtotal = $total;
    //             $shppingtotal = $shippingLines[0]['total'];
    //             $total += $shppingtotal;
    //             return $total;
    // }

    public function processPayment(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 401);
        }
        $uid = $user->ID;
        $agent = $request->userAgent() ?? "Unknown Device";
        $ip = $request->ip() ?? "0.0.0.0";
        $checkout = Checkout::where('user_id', $user->ID)->first();
        $billingInfo = $checkout->billing;
        $shippingInfo = $checkout->shipping;
        $lineItems = $request->input('line_items');
        $paytype = $request->input('paymentType');
        $stateType = $user->mmtax ?? $request->input('stateType') ?? 'OS';
        $order_role = $request->input('order_role');
        $shippingLines = $request->input('shipping_lines');

        if ($paytype == 'card') {
            $payment_token = $request->input('payment_token');
            try {
                $total = 0;
                $checkout->update(
                    [
                        'extra' => $lineItems,
                        'paymentType' => $paytype,
                    ]
                );
                $orderData = Checkout::where('user_id', $user->ID)->first();
                $isVape = false;
                $order_tax = 0;
                $ordertotalQTY = 0;
                $productnames = [];
                $is_free = false;
                $percentageDiscount = 0;
                $cartDiscount = 0;
                $couponIDs = [];
                $isPercentageCoupone = false;
                foreach ($orderData['extra'] as $item) {
                    $ordertotalQTY += $item['quantity'];
                    $subtotal = $item['product_price'];

                    $productnames[] = $item['product_name'];

                    if ($item['isVape'] == true) {
                        $order_tax += $item['quantity'] * $item['taxPerUnit'];
                        $order_tax = round($order_tax, 1);
                        $isVape = true;
                    } else {
                        $subtotal = $subtotal + ($item['taxPerUnit'] ?? 0);
                    }
                    try {
                        if (isset($item['discount_amt']) && $item['discount_amt']) {
                            $cartDiscount += $item['discount_amt'];

                            $couponIDs[] = $item['applicable_rules'][0]['rule_id'];
                            $isPercentageCoupone = true;
                        }
                    } catch (\Throwable $th) {
                    }

                    $float1 = $item['quantity'] * $subtotal;
                    $total += round($float1, 2);
                }

                //cart discount 
                $cartDiscountTax = 0;
                if ($cartDiscount > 0 && $isVape) {
                    $cartDiscountTax = $cartDiscount * 0.15;
                    $cartDiscountTax = round($cartDiscountTax, 2);
                }
                $cartDiscount = round($cartDiscount, 2);
                $total = $total - $cartDiscount + $order_tax;

                $subtotal = $total;
                $shppingtotal = $shippingLines[0]['total'];
                $total += $shppingtotal;

                $checkout->update(
                    [
                        'total' => $total,
                    ]
                );

                if (isset($billingInfo['postcode'])) {
                    $billingInfo['zipcode'] = $billingInfo['postcode'];
                    unset($billingInfo['postcode']);
                }
                if (isset($shippingInfo['postcode'])) {
                    $shippingInfo['zipcode'] = $shippingInfo['postcode'];
                    unset($shippingInfo['postcode']);
                }
                $validShippingKeys = [
                    "shipping_first_name" => "first_name",
                    "shipping_last_name" => "last_name",
                    "shipping_company" => "company",
                    "shipping_address1" => "address_1",
                    "address2" => "address_2",
                    "shipping_city" => "city",
                    "shipping_state" => "state",
                    "shipping_zip" => "zipcode",
                    "shipping_country" => "country",
                    "shipping_email" => null
                ];

                $validBillingKeys = [
                    "first_name" => "first_name",
                    "last_name" => "last_name",
                    "company" => "company",
                    "address1" => "address_1",
                    "address2" => "address_2",
                    "city" => "city",
                    "state" => "state",
                    "zip" => "zipcode",
                    "country" => "country",
                    "phone" => "phone",
                    "fax" => null,
                    "email" => "email"
                ];

                // Restructure shipping info
                $restructuredShipping = [];
                foreach ($validShippingKeys as $newKey => $oldKey) {
                    $restructuredShipping[$newKey] = $oldKey !== null && isset($shippingInfo[$oldKey]) ? $shippingInfo[$oldKey] : null;
                }

                // Restructure billing info
                $restructuredBilling = [];
                foreach ($validBillingKeys as $newKey => $oldKey) {
                    $restructuredBilling[$newKey] = $oldKey !== null && isset($billingInfo[$oldKey]) ? $billingInfo[$oldKey] : null;
                }

                // Output the restructured arrays
                $shippingInfo = $restructuredShipping;
                $billingInfo = $restructuredBilling;
                $saleData = $this->doSale($total, $payment_token, $billingInfo, $shippingInfo);
                $paymentResult = $this->_doRequest($saleData);

                
                if (!$paymentResult['status']) {
                    return response()->json([
                        'status' => false,
                        'message' => $paymentResult,
                        'uniqueId' => null
                    ], 200);
                }
               
                $order_qry_result= $this->orderCreate($request, $paytype, $order_role, $user, $ip, $agent, $checkout, $shippingLines, $total, $ordertotalQTY, $isVape, $stateType, $productnames, $cartDiscount, $cartDiscountTax, $shppingtotal, $transcationID=null);
           
            } catch (\Throwable $th) {
                return response()->json([
                    'status' => false,
                    'message' => $th->getMessage()
                ], 400);
            }
        } else if ($paytype == 'onaccount') {
            try {
                $total = 0;
                $checkout->update(
                    [
                        'extra' => $lineItems,
                        'paymentType' => $paytype,
                    ]
                );
                $orderData = Checkout::where('user_id', $user->ID)->first();

                $isVape = false;
                $order_tax = 0;
                $ordertotalQTY = 0;
                $productnames = [];
                $is_free = false;
                $percentageDiscount = 0;
                $cartDiscount = 0;
                $couponIDs = [];
                $isPercentageCoupone = false;
                foreach ($orderData['extra'] as $item) {
                    $ordertotalQTY += $item['quantity'];
                    $subtotal = $item['product_price'];
                    $productnames[] = $item['product_name'];

                    if ($item['isVape'] == true) {
                        $order_tax += $item['quantity'] * $item['taxPerUnit'];
                        $order_tax = round($order_tax, 1);
                        $isVape = true;
                    } else {
                        $subtotal = $subtotal + ($item['taxPerUnit'] ?? 0);
                    }
                    //percentage discount only
                    try {
                        if (isset($item['discount_amt']) && $item['discount_amt']) {
                            $cartDiscount += $item['discount_amt'];

                            $couponIDs[] = $item['applicable_rules'][0]['rule_id'];
                            $isPercentageCoupone = true;
                        }
                    } catch (\Throwable $th) {
                    }

                    $float1 = $item['quantity'] * $subtotal;
                    $total += round($float1, 2);
                }

                $cartDiscountTax = 0;
                if ($cartDiscount > 0 && $isVape) {
                    $cartDiscountTax = $cartDiscount * 0.15;
                    $cartDiscountTax = round($cartDiscountTax, 2);
                }
                $cartDiscount = round($cartDiscount, 2);
                $total = $total - $cartDiscount + $order_tax;

                $subtotal = $total;
                $shppingtotal = $shippingLines[0]['total'];
                $total += $shppingtotal;

                $checkout->update(
                    [
                        'total' => $total,
                    ]
                );
                $order_qry_result= $this->orderCreate($request, $paytype, $order_role, $user, $ip, $agent, $checkout, $shippingLines, $total, $ordertotalQTY, $isVape, $stateType, $productnames, $cartDiscount, $cartDiscountTax, $shppingtotal, $transcationID=null);
                if($order_qry_result['status']==false){
                    return response()->json([
                        'status' => false,
                        'message' => "Order Failed",
                        'Order status'=>''
                    ]);
                }
                return response()->json([
                    'status' => true,
                    'message' => "Order Failded",
                    'Order status'=>''
                ]);
            } catch (Exception $e) {
                return response()->json([
                    'status' => false,
                    'message' => $e->getMessage()
                ], 400);
            }
        }
    }


    private function orderCreate(Request $request, $paytype, $order_role, $user, $ip, $agent, $checkout, $shippingLines, $total, $ordertotalQTY, $isVape, $stateType, $productnames, $cartDiscount, $cartDiscountTax, $shppingtotal, $transcationID=null)
    {
        $orderData = Checkout::where('user_id', $user->ID)->first();
        try {
            DB::beginTransaction();
            $options = DB::select("SELECT option_value FROM wp_options WHERE option_name= 'wt_last_order_number'");
            $currentValue = (int)$options[0]->option_value;
            $newValue = $currentValue + 1;
            DB::update("UPDATE wp_options SET option_value = ? WHERE option_name = 'wt_last_order_number'", [$newValue]);
            $wp_home_url = config('services.wpurl.homeurl');

            $orderId = DB::table('wp_posts')->insertGetId([
                'post_author' =>  $user->ID,
                'post_date' => now(),
                'post_date_gmt' => now(),
                'post_content' => '',
                'post_title' => 'Order',
                'to_ping' => '',
                'pinged' => '',
                'post_content_filtered' => '',
                'post_excerpt' => '',
                'post_status' => 'wc-processing',
                'comment_status' => 'open',
                'ping_status' => 'closed',
                'post_name' => 'order-' . uniqid(),
                'post_modified' => now(),
                'post_modified_gmt' => now(),
                'post_type' => 'shop_order',
                'guid' => $wp_home_url . '/?post_type=shop_order&p=' . uniqid(),
            ]);
            $state = $orderData['shipping']['state'];
            if ($shippingLines[0]['total']) {
                $floattotal = 15.00;
            } else {
                $floattotal = 0.00;
            }

            //order wp_postmeta data
            $metaData = [
                ['post_id' => $orderId, 'meta_key' => '_billing_first_name', 'meta_value' => $orderData['billing']['first_name']],
                ['post_id' => $orderId, 'meta_key' => '_billing_last_name', 'meta_value' => $orderData['billing']['last_name']],
                ['post_id' => $orderId, 'meta_key' => '_billing_address_1', 'meta_value' => $orderData['billing']['address_1']],
                ['post_id' => $orderId, 'meta_key' => '_billing_address_2', 'meta_value' => $orderData['billing']['address_2']],
                ['post_id' => $orderId, 'meta_key' => '_billing_city', 'meta_value' => $orderData['billing']['city']],
                ['post_id' => $orderId, 'meta_key' => '_billing_state', 'meta_value' => $orderData['billing']['state']],
                ['post_id' => $orderId, 'meta_key' => '_billing_postcode', 'meta_value' => $orderData['billing']['postcode']],
                ['post_id' => $orderId, 'meta_key' => '_billing_country', 'meta_value' => $orderData['billing']['country']],
                ['post_id' => $orderId, 'meta_key' => '_billing_email', 'meta_value' => $orderData['billing']['email']],
                ['post_id' => $orderId, 'meta_key' => '_billing_phone', 'meta_value' => $orderData['billing']['phone']],
                ['post_id' => $orderId, 'meta_key' => '_shipping_first_name', 'meta_value' => $orderData['shipping']['first_name']],
                ['post_id' => $orderId, 'meta_key' => '_shipping_last_name', 'meta_value' => $orderData['shipping']['last_name']],
                ['post_id' => $orderId, 'meta_key' => '_shipping_address_1', 'meta_value' => $orderData['shipping']['address_1']],
                ['post_id' => $orderId, 'meta_key' => '_shipping_address_2', 'meta_value' => $orderData['shipping']['address_2']],
                ['post_id' => $orderId, 'meta_key' => '_shipping_city', 'meta_value' => $orderData['shipping']['city']],
                ['post_id' => $orderId, 'meta_key' => '_shipping_state', 'meta_value' => $orderData['shipping']['state']],
                ['post_id' => $orderId, 'meta_key' => '_shipping_postcode', 'meta_value' => $orderData['shipping']['postcode']],
                ['post_id' => $orderId, 'meta_key' => '_shipping_country', 'meta_value' => $orderData['shipping']['country']],
                ['post_id' => $orderId, 'meta_key' => '_payment_method', 'meta_value' => $orderData['paymentType']],
                ['post_id' => $orderId, 'meta_key' => '_payment_method_title', 'meta_value' => 'Credit-Debit Card'],
                ['post_id' => $orderId, 'meta_key' => '_transaction_id', 'meta_value' => $transcationID ?? $orderId], 
                ['post_id' => $orderId, 'meta_key' => '_order_total', 'meta_value' => $total],
                ['post_id' => $orderId, 'meta_key' => '_order_currency', 'meta_value' => 'USD'],
                ['post_id' => $orderId, 'meta_key' => 'mm_field_ITX', 'meta_value' => $isVape ? 0 : null],
                ['post_id' => $orderId, 'meta_key' => 'mm_field_CID', 'meta_value' => $user->account ?? null],
                ['post_id' => $orderId, 'meta_key' => 'mm_login_id', 'meta_value' => $user->user_email ?? null],
                ['post_id' => $orderId, 'meta_key' => '_order_key', 'meta_value' => 'wc_order_' . uniqid()],
                ['post_id' => $orderId, 'meta_key' => '_customer_user', 'meta_value' => $user->ID],
                ['post_id' => $orderId, 'meta_key' => '_created_via', 'meta_value' => 'checkout'],
                ['post_id' => $orderId, 'meta_key' => '_order_stock_reduced', 'meta_value' => 'yes'],
                ['post_id' => $orderId, 'meta_key' => '_billing_address_index', 'meta_value' => implode(' ', $orderData['billing'])],
                ['post_id' => $orderId, 'meta_key' => '_shipping_address_index', 'meta_value' => implode(' ', $orderData['shipping'])],
                ['post_id' => $orderId, 'meta_key' => '_order_number', 'meta_value' => $newValue],
                ['post_id' => $orderId, 'meta_key' => '_cart_discount', 'meta_value' => $cartDiscount ?? 0],
                ['post_id' => $orderId, 'meta_key' => '_cart_discount_tax', 'meta_value' => $cartDiscountTax ?? 0],
                ['post_id' => $orderId, 'meta_key' => '_order_tax', 'meta_value' => $order_tax ?? 0],
                ['post_id' => $orderId, 'meta_key' => '_order_shipping', 'meta_value' => $floattotal],
                ['post_id' => $orderId, 'meta_key' => '_order_shipping_tax', 'meta_value' => 0],
            ];
            //filter coupon logic


            //order wp_postmeta data
            foreach ($metaData as $meta) {
                OrderMeta::insert($meta);
            }
            if ($stateType == 'EX') {
                $metaValueST = 'EX';
            } elseif ($orderData['shipping']['state'] == 'IL') {
                $metaValueST =  'IL';
            } else {
                $metaValueST =  'OS';
            }
            OrderMeta::insert([
                'post_id' => $orderId,
                'meta_key' => 'mm_field_TXC',
                'meta_value' => $metaValueST,
            ]);
            $totalAmount = $total;
            $productCount = count($orderData['extra']);


            //order items lines
            $id1 = DB::table('wp_woocommerce_order_items')->insertGetId([
                'order_id' => $orderId,
                'order_item_name' => $shippingLines[0]['method_title'],
                'order_item_type' => 'shipping'
            ]);
            $productnamesString = implode(',', $productnames);


            $shippingtaxmeta = [
                ['order_item_id' => $id1, 'meta_key' => 'taxes', 'meta_value' =>  serialize(['total' => [0]])],
                ['order_item_id' => $id1, 'meta_key' => 'total_tax', 'meta_value' => 0],
                ['order_item_id' => $id1, 'meta_key' => 'Items', 'meta_value' => $productnamesString ?? ' '],
                ['order_item_id' => $id1, 'meta_key' => 'cost', 'meta_value' => $floattotal],
                ['order_item_id' => $id1, 'meta_key' => 'instance_id', 'meta_value' => ($shippingLines[0]['method_id'] == 'flat_rate') ? 1 : 2],
                ['order_item_id' => $id1, 'meta_key' => 'method_id', 'meta_value' => $shippingLines[0]['method_id']],
            ];

            //woo removed data rollback job
            if ($floattotal > 0) {
                Buffer::create([
                    'order_id' => $orderId,
                    'shipping' => $shippingLines[0]['method_title'],
                ]);
            }
            if ($cartDiscount > 0) {
                Buffer::create([
                    'order_id' => $orderId,
                    'shipping' => $cartDiscount,
                    'extra' => $cartDiscountTax
                ]);
            }
            foreach ($shippingtaxmeta as $meta) {
                OrderItemMeta::insert($meta);
            }
            if ($isVape) {
                $id2 = DB::table('wp_woocommerce_order_items')->insertGetId([
                    'order_id' => $orderId,
                    'order_item_name' => 'IL-STATE TAX-1',
                    'order_item_type' => 'tax'
                ]);
                $metaILTax = [
                    ['order_item_id' => $id2, 'meta_key' => 'rate_percent', 'meta_value' => $shppingtotal],
                    ['order_item_id' => $id2, 'meta_key' => 'shipping_tax_amount', 'meta_value' => 0],
                    ['order_item_id' => $id2, 'meta_key' => 'tax_amount', 'meta_value' => $order_tax ?? 0],
                    ['order_item_id' => $id2, 'meta_key' => 'label', 'meta_value' => 'State Tax'],
                    ['order_item_id' => $id2, 'meta_key' => 'compound', 'meta_value' => ''],
                    ['order_item_id' => $id2, 'meta_key' => 'rate_id', 'meta_value' => 1],
                ];
                foreach ($metaILTax as $meta) {
                    OrderItemMeta::insert($meta);
                }
            }

            foreach ($orderData['extra'] as $item) {
                $orderItemId = DB::table('wp_woocommerce_order_items')->insertGetId([
                    'order_id' => $orderId,
                    'order_item_name' => $item['product_name'],
                    'order_item_type' => 'line_item'
                ]);

                Cart::where('user_id', $user->ID)
                    ->where('product_id', $item['product_id'])
                    ->where('variation_id', $item['variation_id'] ?? null)
                    ->delete();

                $productPrice = $item['product_price'];
                $linetotal = 0;
                $iLTax = 0;
                $initialPrice = 0;

                //ml tax cal
                if ($item['isVape'] == true) {

                    $iLTax = $item['quantity'] * $item['taxPerUnit'];
                    $iLTax = round($iLTax, 2);
                } else {
                    $productPrice = $productPrice + ($item['taxPerUnit'] ?? 0);
                }
                //round off val 
                $float2 = 0.00;
                $float2 = $item['quantity'] * $productPrice;
                $float2 = round($float2, 2);
                $linetotal += $float2;
                $taxAmount = (float) ($iLTax ?? 0);

                $serializedData = sprintf(
                    'a:2:{s:5:"total";a:1:{i:1;s:6:"%.2f";}s:8:"subtotal";a:1:{i:1;s:6:"%.2f";}}',
                    $taxAmount,
                    $taxAmount
                );
                $float3 = 0.00;
                $float3 = $item['quantity'] * $item['taxPerUnit'];
                $indirect_tax_amount = round($float3, 2);
                //IL no tax
                if ($orderData['shipping']['state'] == 'IL' && $item['isVape'] == true) {
                    $indirect_tax_amount = 0.00;
                }


                //line item meta data 
                $itemMeta = [
                    ['order_item_id' => $orderItemId, 'meta_key' => '_product_id', 'meta_value' => $item['product_id']],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_variation_id', 'meta_value' => $item['variation_id'] ?? 0],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_qty', 'meta_value' => $item['quantity']],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_reduced_stock', 'meta_value' => $item['quantity']],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_tax_class', 'meta_value' => $item['tax_class'] ?? ''],
                    ['order_item_id' => $orderItemId, 'meta_key' => 'flavor', 'meta_value' => implode(',', $item['variation']) ?? ''],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_basis', 'meta_value' => $item['ml1'] * $item['quantity'] ?? $item['ml2'] * $item['quantity'] ?? 0], //
                    ['order_item_id' => $orderItemId, 'meta_key' => '_wwp_wholesale_priced', 'meta_value' => 'yes'],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_wwp_wholesale_role', 'meta_value' => $order_role],

                    ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_amount', 'meta_value' => $indirect_tax_amount ?? 0],

                    ['order_item_id' => $orderItemId, 'meta_key' => '_line_total', 'meta_value' => $linetotal ?? 0], //
                    ['order_item_id' => $orderItemId, 'meta_key' => '_line_subtotal', 'meta_value' => $linetotal ?? 0], //

                    ['order_item_id' => $orderItemId, 'meta_key' => '_line_subtotal_tax', 'meta_value' => $iLTax ?? 0],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_line_tax', 'meta_value' => $iLTax ?? 0],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_line_tax_data', 'meta_value' =>  $serializedData],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_basis_j2', 'meta_value' => 0],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_amount_j2', 'meta_value' => 0],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_basis_j1', 'meta_value' => 0],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_amount_j1', 'meta_value' => 0],
                ];

                //discount type: free Product 
                if (isset($item['is_free_product']) && $item['is_free_product']) {
                    $discountId = $item['discount_id'];

                    $initialPrice = $item['initial_price'];
                    $discounted_price = $productPrice;
                    $initial_price_based_on_tax_settings = $initialPrice;
                    $discounted_price_based_on_tax_settings = $productPrice;
                    $saved_amount = $initialPrice - $discounted_price;
                    $saved_amount_based_on_tax_settings = $saved_amount;


                    $metaValue = [
                        'initial_price' => $initialPrice,
                        'discounted_price' => $discounted_price,
                        'initial_price_based_on_tax_settings' => $initial_price_based_on_tax_settings,
                        'discounted_price_based_on_tax_settings' => $discounted_price_based_on_tax_settings,
                        'applied_rules' => [],
                        'saved_amount' => $saved_amount,
                        'saved_amount_based_on_tax_settings' => $saved_amount_based_on_tax_settings,
                        'is_free_product' => $item['is_free_product']
                    ];

                    $serializedMetaValue = serialize($metaValue);

                    $itemMeta[] = ['order_item_id' => $orderItemId, 'meta_key' => '_wdr_discounts', 'meta_value' => $serializedMetaValue];
                    $variation_id = $item['variation_id'] ?? 0;
                    $product_id = $item['product_id'];
                    if ($variation_id) {
                        $stockLevel = ProductMeta::where('post_id', $variation_id)->where('meta_key', '_stock')->value('meta_value');
                        $newStockLevel = max(0, $stockLevel - $item['quantity']);
                        ProductMeta::where('post_id', $variation_id)->where('meta_key', '_stock')->update(['meta_value' => $newStockLevel]);
                    } else {
                        $stockLevel = ProductMeta::where('post_id', $product_id)->where('meta_key', '_stock')->value('meta_value');
                        $newStockLevel = max(0, $stockLevel - $item['quantity']);
                        ProductMeta::where('post_id', $product_id)->where('meta_key', '_stock')->update(['meta_value' => $newStockLevel]);
                    }
                }

                foreach ($itemMeta as $meta) {
                    OrderItemMeta::insert($meta);
                }

                $unitshippingCharge = (float) ($shppingtotal / max($ordertotalQTY, 1)) * $item['quantity'];
                $done = DB::table('wp_wc_order_product_lookup')->insert([
                    'order_item_id' => $orderItemId,
                    'order_id' => $orderId,
                    'product_id' => $item['product_id'],
                    'variation_id' => $item['variation_id'] ?? 0,
                    'customer_id' => $user->ID,
                    'date_created' => now(),
                    'product_qty' => $item['quantity'],
                    'product_net_revenue' => $linetotal,
                    'product_gross_revenue' => $isVape ? $totalAmount : 0,
                    'tax_amount' => $iLTax ?? 0,
                    'coupon_amount' => 0,
                    'shipping_amount' => $unitshippingCharge ?? 0,
                    'shipping_tax_amount' => 0,
                ]);
            }
            DB::table('wp_wc_orders')->insert([
                'id' => $orderId,
                'status' => 'wc-processing',
                'currency' => 'USD',
                'type' => 'shop_order',
                'tax_amount' => $order_tax ?? 0,
                'total_amount' => $totalAmount,
                'customer_id' => $user->ID,
                'billing_email' => $orderData['billing']['email'],
                'date_created_gmt' => now(),
                'date_updated_gmt' => now(),
                'parent_order_id' => 0,
                'payment_method' => $orderData['paymentType'],
                'payment_method_title' => 'Credit-Debit Card',
                'transaction_id' => uniqid(),
                'ip_address' => $ip,
                'user_agent' => $agent,
                'customer_note' => ''
            ]);
            try {
                $billingCompany = $orderData['billing']['company'];
                $shippingCompany = $orderData['shipping']['company'];
            } catch (\Throwable $th) {
                $billingCompany = '';
                $shippingCompany = '';
            }
            DB::table('wp_wc_order_addresses')->insert([
                [
                    'order_id' => $orderId,
                    'address_type' => 'billing',
                    'first_name' => $orderData['billing']['first_name'],
                    'last_name' => $orderData['billing']['last_name'],
                    'company' => $billingCompany ?? '',
                    'address_1' => $orderData['billing']['address_1'],
                    'address_2' => $orderData['billing']['address_2'],
                    'city' => $orderData['billing']['city'],
                    'state' => $orderData['billing']['state'],
                    'postcode' => $orderData['billing']['postcode'],
                    'country' => $orderData['billing']['country'],
                    'email' => $orderData['billing']['email'],
                    'phone' => $orderData['billing']['phone']
                ],
                [
                    'order_id' => $orderId,
                    'address_type' => 'shipping',
                    'first_name' => $orderData['shipping']['first_name'],
                    'last_name' => $orderData['shipping']['last_name'],
                    'company' => $shippingCompany ?? '',
                    'address_1' => $orderData['shipping']['address_1'],
                    'address_2' => $orderData['shipping']['address_2'],
                    'city' => $orderData['shipping']['city'],
                    'state' => $orderData['shipping']['state'],
                    'postcode' => $orderData['shipping']['postcode'],
                    'country' => $orderData['shipping']['country'],
                    'email' => $orderData['billing']['email'],
                    'phone' => $orderData['billing']['phone']
                ]
            ]);

            DB::table('wp_wc_order_stats')->insert([
                'order_id' => $orderId,
                'parent_id' => 0,
                'status' => 'wc-processing',
                'date_created' => now(),
                'date_created_gmt' => now(),
                'num_items_sold' => $productCount,
                'total_sales' => $totalAmount,
                'tax_total' => 0,
                'shipping_total' => $shippingLines[0]['total'],
                'net_total' => $totalAmount,
                'returning_customer' => 0,
                'customer_id' => $user->ID,
                'date_paid' => null,
                'date_completed' => null,
            ]);

            $orderNotes = [
                [
                    'comment_post_ID' => $orderId,
                    'comment_author' => 'Laravel',
                    'comment_author_email' => '',
                    'comment_author_url' => '',
                    'comment_author_IP' => $ip,
                    'comment_date' => now(),
                    'comment_date_gmt' => now(),
                    'comment_content' => 'Order status changed from Pending payment to Processing (express).',
                    'comment_karma' => 0,
                    'comment_approved' => 1,
                    'comment_agent' => $agent,
                    'comment_type' => 'order_note',
                    'comment_parent' => 0,
                    'user_id' => 0,
                ],
                [
                    'comment_post_ID' => $orderId,
                    'comment_author' => 'Laravel',
                    'comment_author_email' => '',
                    'comment_author_url' => '',
                    'comment_author_IP' => $ip,
                    'comment_date' => now(),
                    'comment_date_gmt' => now(),
                    'comment_content' => 'Credit-Debit Card complete', // (Charge ID: ' . $paymentResult['data']['transactionid'],
                    'comment_karma' => 0,
                    'comment_approved' => 1,
                    'comment_agent' => $agent,
                    'comment_type' => 'order_note',
                    'comment_parent' => 0,
                    'user_id' => 0,
                ],
            ];
            foreach ($orderNotes as $note) {
                DB::table('wp_comments')->insert($note);
            }

            $checkout->delete();
            DB::commit();
            $email = $orderData['billing']['email'];
            $username = $orderData['billing']['first_name'] . ' ' . $orderData['billing']['last_name'];
            $deliveryDate = '3 working Days';
            $businessAddress = implode(' ', $orderData['shipping']);
            SendOrderConfirmationEmail::dispatch(
                $email,
                $newValue,
                $username,
                $deliveryDate,
                $businessAddress
            );
        } catch (\Throwable $th) {
            DB::rollBack();
            return ['status' => false,'message'=> 'Order creation failed: ' . $th->getMessage(),'order'=>null,'orderNo' => null ];
        }
        return ['status' => true,'message'=>'Order Created','order'=>$orderId,'orderNo' => $newValue];
    }
}
