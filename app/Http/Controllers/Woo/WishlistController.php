<?php

namespace App\Http\Controllers\Woo;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DiscountRuleController;
use App\Models\DiscountRule;
use App\Models\Wishlist;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class WishlistController extends Controller
{
    
    public function getWishlist(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json([
                'message' => 'User not found',
                'status' => false,
            ], 200);
        }
        $userId=$user->ID;
        $wishlistItems = Wishlist::where('user_id', $userId)->get();
        $discountRuleController = new DiscountRuleController();

        $wishlist = $wishlistItems->map(function ($wishlistItem) use ($discountRuleController, $request) {
            // Fetch discount rules for the product
            $response = $discountRuleController->show($request, $wishlistItem->product_id);
            $data = json_decode($response->getContent(), true);

            // Include product and discount rules in wishlist
            return $data;
        });
        return response()->json($wishlist);
    }

    public function addToWishlist(Request $request)
    {
        $request->validate([
            'product_id' => 'required'
        ]);

        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json([
                'message' => 'User not found',
                'status' => false,
            ], 200);
        }
        $userId=$user->ID;
        $productId = $request->input('product_id');

        $existingWishlistItem = Wishlist::where('user_id', $userId)->where('product_id', $productId)->first();
        if ($existingWishlistItem) {
            return response()->json(['status' => true,'message' => 'Product already in wishlist'], 400);
        }

        Wishlist::create([
            'user_id' => $userId,
            'product_id' => $productId
        ]);

        return response()->json(['status' => true,'message' => 'Product added to wishlist'], 200);
    }

    public function removeFromWishlist(Request $request)
    {
        $request->validate([
            'product_id' => 'required'
        ]);

        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json([
                'message' => 'User not found',
                'status' => false,
            ], 200);
        }
        $userId=$user->ID;
        $productId = $request->input('product_id');

        $wishlistItem = Wishlist::where('user_id', $userId)->where('product_id', $productId)->first();
        if (!$wishlistItem) {
            return response()->json(['status' => false,'message' => 'Product not found in wishlist'], 404);
        }

        $wishlistItem->delete();

        return response()->json(['status' => true,'message' => 'Product removed from wishlist'], 200);
    }

    public function removeAllFromWishlist()
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json([
                'message' => 'User not found',
                'status' => false,
            ], 200);
        }
        $userId=$user->ID;

        Wishlist::where('user_id', $userId)->delete();

        return response()->json(['status' => true,'message' => 'All products removed from wishlist'], 200);
    }
}
