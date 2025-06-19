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
        Schema::create('product_limit_session', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_variation_id');
            $table->unsignedBigInteger('user_id');
            $table->integer('order_count')->default(0);
            $table->integer('session_id')->nullable();
            $table->integer('blocked_attemps')->nullable();
            $table->dateTime('blocked_attemp_time')->nullable();
            $table->longText('log')->nullable();
            $table->timestamps();
            $table->index(['product_variation_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_limit_session');
    }
};
