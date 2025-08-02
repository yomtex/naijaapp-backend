<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ModifyPinColumnOnUsersTable extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin', 100)->nullable()->change(); // Make sure it's long enough
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin', 6)->nullable()->change(); // Revert if needed
        });
    }
}
