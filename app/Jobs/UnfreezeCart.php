<?php

namespace App\Jobs;

use App\Models\Cart;
use App\Models\Checkout;
use App\Models\ProductMeta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UnfreezeCart implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $userId;

    /**
     * Create a new job instance.
     *
     * @param int $userId
     */
    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // unfreezed
        $data = Checkout::where('user_id', $this->userId)->first();
        if ($data && $data->isFreeze) {
            $cartItems = Cart::where('user_id', $this->userId)->get();

            foreach ($cartItems as $cartItem) {
                $product = $cartItem->product;
                $variation = $cartItem->variation;

                if ($variation) {
                    $stockLevel = ProductMeta::where('post_id', $variation->ID)
                        ->where('meta_key', '_stock')
                        ->first();
                    if ($stockLevel) {
                        $stockLevel->meta_value += $cartItem->quantity;
                        $stockLevel->save();
                    }
                } else {
                    $stockLevel = ProductMeta::where('post_id', $product->ID)
                        ->where('meta_key', '_stock')
                        ->first();
                    if ($stockLevel) {
                        $stockLevel->meta_value += $cartItem->quantity;
                        $stockLevel->save();
                    }
                }
            }

            Checkout::where('user_id', $this->userId)->update(['isFreeze' => false]);
            Log::info('unfreeze queue-> ' . $this->userId);
        }else {
            Log::info('Not freezed-> ' .$this->userId);
        }
    }
}
