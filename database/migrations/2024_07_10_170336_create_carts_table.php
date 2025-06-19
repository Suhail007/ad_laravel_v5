<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->integer('quantity');
            $table->timestamps();
    
            $table->foreign('user_id')->references('ID')->on('wp_users')->onDelete('cascade');
            $table->foreign('product_id')->references('ID')->on('wp_posts')->onDelete('cascade');
            $table->foreign('variation_id')->references('ID')->on('wp_posts')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
