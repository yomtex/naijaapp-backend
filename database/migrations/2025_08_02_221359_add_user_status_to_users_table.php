<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // In the generated migration file
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('user_status', ['active', 'banned','suspended'])->default('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('user_status');
        });
    }

};
