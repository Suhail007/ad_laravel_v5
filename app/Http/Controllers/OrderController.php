<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CustomerOrder;
use App\Models\Order;
use App\Models\OrderMeta;
use App\Models\ProductMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $orders = CustomerOrder::where('type', 'shop_order')->where('status','!=','trash')->where('customer_id', $user->ID)->with(['items', 'items.meta', 'meta']) ->orderBy('id', 'desc')->paginate();
        if($orders){
            return response()->json(['status'=>true,'data'=>$orders]);
        }
        return response()->json(['status'=>false,'data'=>'Orders Not Found Create A order']);
    }

    public function show($id)
    {
        $order =  CustomerOrder::with(['items', 'items.meta', 'meta'])->where('type', 'shop_order')->where('status','!=','trash')->findOrFail($id);
        return response()->json($order);
    }
    public function oldOrders(Request $request){
        $user = JWTAuth::parseToken()->authenticate();
        $threeMonthsAgo = \Carbon\Carbon::now()->subMonths(3);
        $data = $user->capabilities;
        $isAdmin = false;
        foreach ($data as $key => $value) {
            if ($key == 'administrator') {
                $isAdmin = true;
            }
        }
        $query = CustomerOrder::where('type', 'shop_order')
            ->where('status', '!=', 'trash')
            ->where('date_created_gmt', '<', $threeMonthsAgo)  
            ->with(['items', 'items.meta', 'meta'])
            ->orderBy('id', 'desc');
        if (!$isAdmin) {
            $query->where('customer_id', $user->ID);
        }
        $orders = $query->paginate();
        if ($orders->isNotEmpty()) {
            return response()->json(['status' => true, 'data' => $orders]);
        }
        return response()->json(['status' => false, 'data' => 'Orders Not Found. Create An Order']);
    }
    
    

    public function oldOrder(Request $request, $id){
        $user = JWTAuth::parseToken()->authenticate();
        $threeMonthsAgo = \Carbon\Carbon::now()->subMonths(3);
        $data = $user->capabilities;
        $isAdmin = false;
        foreach ($data as $key => $value) {
            if ($key == 'administrator') {
                $isAdmin = true;
            }
        }
        $query = CustomerOrder::with(['items', 'items.meta', 'meta'])
            ->where('type', 'shop_order')
            ->where('status', '!=', 'trash')
            ->where('date_created_gmt', '<', $threeMonthsAgo) ; // Exclude orders from the last 3 months
        if (!$isAdmin) {
            $query->where('customer_id', $user->ID);
        }
        $order = $query->findOrFail($id);
        return response()->json($order);
    }
    
    
}
