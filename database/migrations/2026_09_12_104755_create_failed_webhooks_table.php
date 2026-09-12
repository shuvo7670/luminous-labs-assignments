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
        Schema::create('failed_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('provider_event_id')->nullable()->unique();
            $table->unsignedInteger('attempts')->default(1);
            $table->text('last_error');
            $table->timestamp('last_failed_at');
            $table->timestamp('resolved_at')->nullable();
            $table->longText('payload');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('failed_webhooks');
    }
};
