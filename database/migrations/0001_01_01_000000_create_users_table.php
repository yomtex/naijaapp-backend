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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('pin', 6)->nullable(); // stored securely (hashed)
            $table->string('otp_pin', 6)->nullable(); // one-time PIN for actions
            $table->decimal('balance', 16, 2)->default(0); // total money including pending
            $table->decimal('available_balance', 16, 2)->default(0); // usable money
            $table->string('transfer_pin')->nullable(); // Hashed 6-digit PIN
            $table->boolean('transfer_locked')->default(false);
            $table->unsignedInteger('risk_score')->default(0);
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        // Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
