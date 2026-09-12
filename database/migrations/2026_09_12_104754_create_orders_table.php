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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('provider_payment_id');
            $table->string('provider_event_id');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('customer_email');
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->unique(['provider', 'provider_payment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
