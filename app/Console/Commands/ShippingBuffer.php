<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShippingBuffer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:shipping-job';


    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update shipping charges based on buffer table';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Log::info('buffer started');

        try {
            $buffers = DB::table('buffers')->get();

            foreach ($buffers as $buffer) {
                if ($buffer->shipping == 'Flat rate' || $buffer->shipping == 'WANHUB' || $buffer->shipping == 'WANHUB-NA') {
                    $orderItem = DB::table('wp_woocommerce_order_items')
                        ->where('order_id', $buffer->order_id)
                        ->where('order_item_name', $buffer->shipping)
                        ->first();

                    if ($orderItem) {
                        $orderShipping = DB::table('wp_postmeta')
                            ->where('post_id', $buffer->order_id)
                            ->where('meta_key', '_order_shipping')
                            ->value('meta_value');

                        if ($orderShipping === '0') {
                            DB::table('wp_postmeta')
                                ->where('post_id', $buffer->order_id)
                                ->where('meta_key', '_order_shipping')
                                ->update(['meta_value' => '15']);


                            Log::info($buffer->order_id . ' shipping charges updated');
                        }
                        $value = DB::table('wp_posts')->where('id', $buffer->order_id)->value('post_status');
                        if ($value !== 'wc-processing') {
                            DB::table('buffers')
                                ->where('id', $buffer->id)
                                ->delete();
                        }
                    }
                } else {
                    $cartDis = $buffer->shipping;
                    $cartDisTax = $buffer->extra;
                    DB::table('wp_postmeta')
                        ->where('post_id', $buffer->order_id)
                        ->where('meta_key', '_cart_discount')
                        ->update(['meta_value' => $cartDis]);
                    DB::table('wp_postmeta')
                        ->where('post_id', $buffer->order_id)
                        ->where('meta_key', '_cart_discount_tax')
                        ->update(['meta_value' => $cartDisTax]);
                    $value = DB::table('wp_posts')->where('id', $buffer->order_id)->value('post_status');
                    if ($value !== 'wc-processing') {
                        DB::table('buffers')
                            ->where('id', $buffer->id)
                            ->delete();
                    }
                }
            }
        } catch (\Throwable $th) {
            Log::error('Error processing buffers: ' . $th->getMessage());
        }
    }
}
