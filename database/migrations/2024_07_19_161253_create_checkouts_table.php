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
        Schema::create('checkouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->boolean('isFreeze')->default(false);
            $table->json('billing')->nullable();
            $table->json('shipping')->nullable();
            $table->integer('total')->default(0)->nullable();
            $table->string('paymentType')->nullable();
            $table->json('extra')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('ID')->on('wp_users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkouts');
    }
};
