<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            if (!Schema::hasColumn('refresh_tokens', 'device_id')) {
                $table->string('device_id')->nullable()->index(); // UUID from Flutter
            }
            if (!Schema::hasColumn('refresh_tokens', 'device_name')) {
                $table->string('device_name')->nullable(); // e.g. iPhone 15
            }
            if (!Schema::hasColumn('refresh_tokens', 'platform')) {
                $table->string('platform')->nullable(); // android | ios
            }
            if (!Schema::hasColumn('refresh_tokens', 'fcm_token')) {
                $table->text('fcm_token')->nullable();
            }
            if (!Schema::hasColumn('refresh_tokens', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable()->index();
            }
        });
    }

    public function down(): void {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->dropColumn(['device_id','device_name','platform','fcm_token','revoked_at']);
        });
    }
};

