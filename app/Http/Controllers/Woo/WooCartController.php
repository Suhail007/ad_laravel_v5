<?php

namespace App\Http\Controllers\Woo;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Automattic\WooCommerce\Client;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class WooCartController extends Controller
{
    protected $woocommerce;
    public function __construct(Client $woocommerce)
    {
        $this->woocommerce = $woocommerce;
    }
    public function addToCart(Request $request){
        $userId =1;// $request->user()->ID;
        $productId = $request->input('product_id');
        $quantity = $request->input('quantity');
        $variationId = $request->input('variation_id', 0);
        $cartKey = "_woocommerce_persistent_cart_{$userId}";
        $userMeta = DB::table('wp_usermeta')->where('user_id', $userId)->where('meta_key', $cartKey)->first();
        $cartData = $userMeta ? unserialize($userMeta->meta_value) : ['cart' => []];
        $cartItemKey = md5($userId . $productId . $variationId);
        if (isset($cartData['cart'][$cartItemKey])) {
            $cartData['cart'][$cartItemKey]['quantity'] += $quantity;
        } else {
            $cartData['cart'][$cartItemKey] = [
                'key' => $cartItemKey,
                'product_id' => $productId,
                'variation_id' => $variationId,
                'variation' => $request->input('variation', []),
                'quantity' => $quantity,
                'data_hash' => md5($productId . $variationId . time()),
                'line_tax_data' => ['subtotal' => [], 'total' => []],
                'line_subtotal' => $request->input('line_subtotal'),
                'line_subtotal_tax' => 0,
                'line_total' => $request->input('line_total'),
                'line_tax' => 0,
            ];
        }
        $cartMetaValue = serialize($cartData);
        if ($userMeta) {
            DB::table('wp_usermeta')->where('umeta_id', $userMeta->umeta_id)->update(['meta_value' => $cartMetaValue]);
        } else {
            DB::table('wp_usermeta')->insert([
                'user_id' => $userId,
                'meta_key' => $cartKey,
                'meta_value' => $cartMetaValue,
            ]);
        }

        return response()->json(['message' => 'Product added to cart']);
    }

    public function index(Request $request){
        $userId = 1;
        $userMeta = DB::table('wp_usermeta')
            ->where('user_id', $userId)
            ->where('meta_key', 'like', '%_woocommerce_persistent_cart_%')
            ->first();

        if (!$userMeta) {
            return response()->json(['totalQuantity' => 0, 'totalAmount' => 0.0]);
        }

        $cartData = unserialize($userMeta->meta_value);

        $totalQuantity = 0;
        $totalAmount = 0.0;

        foreach ($cartData['cart'] as $item) {
            $totalQuantity += $item['quantity'];
            $totalAmount += $item['quantity'] * $item['line_total']; // Assuming 'line_total' contains the price for each item
        }

        return response()->json([
            'totalQuantity' => $totalQuantity,
            'totalAmount' => $totalAmount
        ]);
    }
    public function show(){
        $userId = 1; // Replace with dynamic user ID as needed
        $userMeta = DB::table('wp_usermeta')
            ->where('user_id', $userId)
            ->where('meta_key', 'like', '%_woocommerce_persistent_cart_%')
            ->first();

        if (!$userMeta) {
            return response()->json([]);
        }

        $cartData = unserialize($userMeta->meta_value);

        $products = [];

        foreach ($cartData['cart'] as $item) {
            $productId = $item['product_id'];
            $variationId = $item['variation_id'];

            // Fetch product details
            $productQuery = Product::with([
                'meta' => function ($query) {
                    $query->whereIn('meta_key', ['_price', '_sku', '_thumbnail_id']);
                }
            ]);

            if ($variationId != 0) {
                // If variation exists, fetch the variation product
                $product = $productQuery->find($variationId);
            } else {
                // Otherwise, fetch the main product
                $product = $productQuery->find($productId);
            }

            if ($product) {
                $products[] = [
                    'ID' => $product->ID,
                    'title' => $product->post_title,
                    'slug' => $product->post_name,
                    'thumbnail_url' => $this->getThumbnailUrl($product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first()),
                    'sku' => $product->meta->where('meta_key', '_sku')->pluck('meta_value')->first(),
                    'price' => $product->meta->where('meta_key', '_price')->pluck('meta_value')->first(),
                    'quantity' => $item['quantity'],
                    'subtotal' => $item['quantity'] * $product->meta->where('meta_key', '_price')->pluck('meta_value')->first(),
                    'variation' => $item['variation'] ?? [] // Include variation details if present
                ];
            }
        }

        return response()->json($products);
    }

    private function getThumbnailUrl($thumbnailId){
        if (!$thumbnailId) {
            return null;
        }
        $attachment = DB::table('wp_posts')->where('ID', $thumbnailId)->first();
        if ($attachment) {
            return $attachment->guid;
        }
        return null;
    }
    public function addToCar(Request $request)
    {
        $userId = JWTAuth::parseToken()->authenticate();
        $productId = $request->input('product_id');
        $quantity = $request->input('quantity');
        $variationId = $request->input('variation_id', 0); 
        $userMeta = DB::table('wp_usermeta')
            ->where('user_id', $userId)
            ->where('meta_key', 'like', '%_woocommerce_persistent_cart_%')
            ->first();
        $cartData = $userMeta ? unserialize($userMeta->meta_value) : ['cart' => []];
        $cartItemKey = md5($userId . $productId . $variationId);
        if (isset($cartData['cart'][$cartItemKey])) {
            $cartData['cart'][$cartItemKey]['quantity'] += $quantity;
        } else {
            $cartData['cart'][$cartItemKey] = [
                'key' => $cartItemKey,
                'product_id' => $productId,
                'variation_id' => $variationId,
                'variation' => $request->input('variation', []),
                'quantity' => $quantity,
                'data_hash' => md5($productId . $variationId . time()),
                'line_tax_data' => ['subtotal' => [], 'total' => []],
                'line_subtotal' => $request->input('line_subtotal'),
                'line_subtotal_tax' => 0,
                'line_total' => $request->input('line_total'),
                'line_tax' => 0,
            ];
        }
        $cartMetaValue = serialize($cartData);
        if ($userMeta) {
            DB::table('wp_usermeta')
                ->where('umeta_id', $userMeta->umeta_id)
                ->update(['meta_value' => $cartMetaValue]);
        } else {
            DB::table('wp_usermeta')
                ->insert([
                    'user_id' => $userId,
                    'meta_key' => 'wp_woocommerce_persistent_cart_' . $userId,
                    'meta_value' => $cartMetaValue,
                ]);
        }

        return response()->json(['message' => 'Product added to cart']);
    }


}
