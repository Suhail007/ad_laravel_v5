<?php

use App\Jobs\BufferJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;


// $flag=0;
// if($flag){

//     Schedule::command('app:freeze-job')->everyTenSeconds();
//     $flag=0;
// } else {

    Schedule::command('app:shipping-job')->everyFiveSeconds();
    // $flag=1;
// }



// Schedule::call(function () {
//     try {
//         $buffers = DB::table('buffers')->get();

//         foreach ($buffers as $buffer) {
//             $orderItem = DB::table('wp_woocommerce_order_items')
//                 ->where('order_id', $buffer->order_id)
//                 ->where('order_item_name', $buffer->shipping)
//                 ->first();

//             if ($orderItem) {
//                 $orderShipping = DB::table('wp_postmeta')
//                     ->where('post_id', $buffer->order_id)
//                     ->where('meta_key', '_order_shipping')
//                     ->value('meta_value');

//                 if ($orderShipping === '0') {
//                     DB::table('wp_postmeta')
//                         ->where('post_id', $buffer->order_id)
//                         ->where('meta_key', '_order_shipping')
//                         ->update(['meta_value' => '15']);

//                     DB::table('buffers')
//                         ->where('id', $buffer->id)
//                         ->delete();
//                     Log::info($buffer->order_id.' shipping charges updated');
//                 }
//             }
//         }
//     } catch (\Throwable $th) {
//         Log::error('Error processing buffers: ' . $th->getMessage());
//     }
        
// })->everyFiveSeconds();


// Schedule::command('shipping:update')->everyTwentySeconds();
// Schedule::job(new BufferJob)->everyFiveSeconds();
// Schedule::command('schedule:run')->everySecond();
// Schedule::command('queue:work')->everySecond();


// use Illuminate\Foundation\Inspiring;
// use Illuminate\Support\Facades\Artisan;
// use Illuminate\Console\Command;
// use Illuminate\Support\Facades\Http;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote')->hourly();

