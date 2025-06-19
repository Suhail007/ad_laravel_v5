<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\LayoutController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PayPalController;
use App\Http\Controllers\Woo\WooCartController;
use App\Http\Controllers\Woo\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WooCommerceController;
// routes/web.php or routes/api.php
use App\Http\Controllers\CartController;
use App\Http\Controllers\Checkout\ProcessOrderController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CleanupController;
use App\Http\Controllers\DiscountRuleController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\Multichannel\GeoRestrictionController;
use App\Http\Controllers\Multichannel\ProductController as MultichannelProductController;
use App\Http\Controllers\Multichannel\ProductVariationSessionLock;
use App\Http\Controllers\MyAcccountController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\UserCouponController;
use App\Http\Controllers\Woo\PublicController;
use App\Http\Controllers\Woo\TestController;
use App\Http\Controllers\Woo\WishlistController;
use Illuminate\Support\Facades\Mail;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login');
Route::post('/register', [LoginController::class, 'register']);
Route::post('password/emailLink', [LoginController::class, 'sendResetLinkEmail']);
Route::get('/profile', [LoginController::class, 'me']);
Route::post('password/reset', [LoginController::class, 'reset']);
Route::post('/file-upload', [LayoutController::class, 'uploadFile']);
Route::group(['middleware' => ['jwt.auth']], function () {
    Route::post('/change-password', [LoginController::class, 'changePassword']);
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::post('/delete-my-account', [LoginController::class, 'deleteMyAccount']);

    //admin layout
    Route::post('/layout', [LayoutController::class, 'store']);
    Route::put('/layout/{id}', [LayoutController::class, 'update']);
    Route::delete('/layout/{id}', [LayoutController::class, 'destroy']);
    Route::post('/mediafile', [LayoutController::class, 'uploadFile']);

    Route::post('/adminUserLogin', [LoginController::class, 'adminlogin']);
    Route::get('/userList/{value}', [LoginController::class, 'users']);

    //multichanel 
    // Geo Restriction Routes
    Route::get('/search-to-apply',[GeoRestrictionController::class,'searchToApply'])->middleware('geo.restriction');
    Route::get('/get-location-list',[GeoRestrictionController::class,'getLocationList'])->middleware('geo.restriction');
    Route::prefix('geo-restrictions')->middleware('geo.restriction')->group(function () {
        Route::get('/', [GeoRestrictionController::class, 'index']);
        Route::post('/', [GeoRestrictionController::class, 'store']);
        Route::get('/{id}', [GeoRestrictionController::class, 'show']);
        Route::put('/{id}', [GeoRestrictionController::class, 'update']);
        Route::delete('/{id}', [GeoRestrictionController::class, 'destroy']);
        Route::post('/{id}/toggle', [GeoRestrictionController::class, 'toggleStatus']);
        Route::post('/preview', [GeoRestrictionController::class, 'preview']);
        Route::post('/{id}/duplicate', [GeoRestrictionController::class, 'duplicate']);
    });
    Route::get('get-variations/{id}',[MultichannelProductController::class,'getProductVariation']);
    Route::post('set-purchase-limit',[ProductVariationSessionLock::class,'updateOrCreate']);
    Route::get('get-purchase-limit-products',[ProductVariationSessionLock::class,'index']);
    Route::get('get-purchase-limit-products-by-id/{id}',[ProductVariationSessionLock::class,'getPurchaseLimitProductById']);
    Route::get('/get-active-purchase-limit-products',[ProductVariationSessionLock::class,'getProductsWithActiveSession']);
    Route::get('/get-inactive-purchase-limit-products',[ProductVariationSessionLock::class,'getProductsWithInactiveSession']);
    Route::get('/deactivate-all-sessions-for-product/{id}', [ProductVariationSessionLock::class, 'deactivateAllSessionsForProduct']);
    Route::get('/activate-all-sessions-for-product/{id}', [ProductVariationSessionLock::class, 'activateAllSessionsForProduct']);
    // Route::post('set-purchase-limit',[MultichannelProductController::class,'updateQuantity']);
    // Route::get('get-purchase-limit-products',[MultichannelProductController::class,'getPurchaseLimitProduct']);
    Route::delete('unset-purchase-limit/{id}',[MultichannelProductController::class,'removePurchaseLimit']);
    Route::get('search-purchase-limit-products',[MultichannelProductController::class,'searchPurchaseLimitProduct']);
    //menu cleanup 
    Route::get('/cleanup',[CleanupController::class,'menuCleanUp']);
    //brand list 
    Route::get('brand-menus', [MenuController::class, 'index']);
    Route::post('brand-menus', [MenuController::class, 'store']);
    Route::get('brand-menus/{id}', [MenuController::class, 'show']);
    Route::put('brand-menus/{id}', [MenuController::class, 'update']);
    Route::delete('brand-menus/{id}', [MenuController::class, 'destroy']);
    //brand custom list sync
    Route::post('/fetch-brands', [MenuController::class, 'fetchAndSaveBrands']);
    //get taxonomy by name/slug


    Route::get('/category-list/{value?}',[CleanupController::class,'category']);
    Route::get('/brand-list/{value?}',[CleanupController::class,'brand']);
    Route::get('/user-list/{value}',[CleanupController::class,'users']);
    //media 
    Route::post('/media/upload', [MediaController::class, 'uploadFile']);
    Route::get('/media', [MediaController::class, 'index']);
    Route::get('/media/{id}', [MediaController::class, 'show']);
    Route::put('/media/{id}', [MediaController::class, 'update']);
    Route::delete('/media/{id}', [MediaController::class, 'destroy']);
    Route::get('/get-u-addresses',[WooCommerceController::class,'getUAddresses']);

    //myaccount
    Route::get('/my-account/addresses',[MyAcccountController::class,'getUserAddresses']);
    Route::post('/my-account/addresses-add', [MyAcccountController::class, 'updateOrCreateAddresses']); //new add
    Route::post('/my-account/address-update', [MyAcccountController::class, 'updateAddress']); //single add update

    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);

    Route::get('history/orders', [OrderController::class, 'oldOrders']);
    Route::get('history/orders/{id}', [OrderController::class, 'oldOrder']);
    //user Cart
    Route::get('/cart/{userId}', [CartController::class, 'getCart']);
    Route::delete('/cart/{id}', [CartController::class, 'deleteFromCart']);
    Route::post('/cart/bulk-add', [CartController::class, 'bulkAddToCart']);
    Route::post('/cart/update', [CartController::class, 'updateCartQuantity']);
    Route::post('/cart/empty',[CartController::class,'empty']);
    Route::post('/cart/bulk-update',[CartController::class,'bulkUpdateCart']);
    Route::post('/cart/bulk-delete',[CartController::class,'bulkDeleteCart']);
    Route::post('/cart/remove',[CartController::class,'removeById']);
    //tax
    Route::get('/carttax',[CartController::class,'tax']);

    //checkout 
    Route::post('/checkout/address',[CheckoutController::class,'checkoutAddress']);
    
    //payment 
    Route::get('/payment-price',[PayPalController::class, 'me']);
    Route::post('/process-payment', [PayPalController::class, 'processPayment']);
    // Route::post('/process-payment',[ProcessOrderController::class, 'processPayment']);
    // Route::post('/payment-process', [CheckoutController::class, 'processPayment']);
    
    
    //discount api
    Route::get('/cart-discount',[DiscountRuleController::class,'index']);
    Route::get('/cart-discount/{id}',[DiscountRuleController::class,'singleDiscount']);
    Route::get('/discount-product/{id}', [DiscountRuleController::class, 'show']);

    Route::prefix('userCoupon')->group(function () {
        Route::get('/', [UserCouponController::class, 'index']);
        Route::get('{id}', [UserCouponController::class, 'show']);
        Route::post('/', [UserCouponController::class, 'store']);
        Route::put('{id}', [UserCouponController::class, 'update']);
        Route::delete('{id}', [UserCouponController::class, 'destroy']);
    });


    //wishlist
    Route::get('/wishlist', [WishlistController::class, 'getWishlist']);
    Route::post('/wishlist/add', [WishlistController::class, 'addToWishlist']);
    Route::post('/wishlist/remove', [WishlistController::class, 'removeFromWishlist']);
    Route::post('/wishlist/remove-all', [WishlistController::class, 'removeAllFromWishlist']);
});
    
Route::get('/offers',[DiscountRuleController::class, 'offers']);
Route::get('/bxgy',[DiscountRuleController::class, 'bxgyOffers']);
Route::get('/percent-sale',[DiscountRuleController::class, 'percentageSale']);
Route::get('/offer/{id}',[DiscountRuleController::class, 'offer']);
Route::post('nmi/webhook',[PayPalController::class,'handleWebhook']);

Route::get('/cleanup',[CleanupController::class,'menuCleanUp']);
Route::get('/mail-brand/{slugs}',[CleanupController::class,'brandProducts']);
//Layouts Public
Route::get('/layout', [LayoutController::class, 'layouts']);
Route::get('/position/{layout}', [LayoutController::class, 'position']);
Route::get('/positionLayout/{layout}/{position}', [LayoutController::class, 'positionLayout']);
Route::get('/positionLayout/{page}', [LayoutController::class, 'pageLayout']);

//Pages
Route::get('/categoryProductV2/{slug}', [ProductController::class, 'categoryProductV3']);
Route::get('/brandProductV2/{slug}', [ProductController::class, 'brandProductV2']);
Route::get('/searchProductsV2', [ProductController::class,'searchProductV3']);  //'searchProducts']); 
Route::get('products/{id}/relatedV2', [ProductController::class, 'getRelatedProductV2']);

Route::get('/categoryProduct/{slug}', [ProductController::class, 'categoryProduct']);
Route::get('/brandProduct/{slug}', [ProductController::class, 'brandProducts']);
Route::get('/searchProducts', [ProductController::class,'searchProductsAll']);  //'searchProducts']); 
Route::get('/searchProductsALL', [ProductController::class, 'searchProductsAll']); //in pro sku cat
Route::post('/productList', [ProductController::class, 'productList']); //
Route::post('/globalSearch/{slug?}', [ProductController::class, 'combineProducts']); //

//product page
Route::get('/product/{slug}', [WooCommerceController::class, 'show']);
Route::get('products/{id}/related', [ProductController::class, 'getRelatedProducts']);

//Sidebar menu
Route::get('/sidebar', [ProductController::class, 'sidebar']);

Route::get('/list', [MenuController::class, 'publiclist']);
Route::get('/flavors', [MenuController::class, 'flavorList']);

// Route::get('/cart', [WooCartController::class, 'index']);
Route::get('/cart-products', [WooCartController::class, 'show']);



Route::get('/log', function () {
    return response()->json(['status' => 'error', 'redirect_url' => '/login']);
})->name('login');

Route::get('/cart-sync',[CleanupController::class,'cartSync']);


Route::post('/createorder',[WooCommerceController::class,'createOrder']);


Route::get('/best-product/{slug}', [PublicController::class, 'show']);

Route::get('/send-test-email', function () {
    // Mail::to('utkarshuklacse@gmail.com')->send(new \App\Mail\OrderSuccess());
    return 'Test email sent!';
});
Route::get('/auto-brand-sync/{value}',[MenuController::class, 'fetchAndSaveBrands']);

Route::get('/sync-category',[PublicController::class,'syncWoocat']);
Route::get('/sync-brands',[PublicController::class,'syncWooBrand']);
Route::get('/sync-product/{slug?}',[PublicController::class,'wooProduct']);
Route::get('/thumbnail/{thumbnailId?}',[PublicController::class,'getThumbnail']);
Route::get('/synProductMeta/{id}',[PublicController::class,'syncProductMeta']);
Route::get('/sync-user',[PublicController::class,'syncUser']);
Route::get('/test-ip',[ProductController::class,'rewealLocation']);

Route::fallback(function () {
    return response()->json([
        'message' => 'API endpoint not found.',
        'status' => false,
    ], 404);
});