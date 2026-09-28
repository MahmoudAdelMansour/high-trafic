<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Denormalized counters so the fan-out can answer "is this author a
     * celebrity?" in O(1) instead of counting follows rows.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('followers_count')->default(0)->after('password');
            $table->unsignedInteger('following_count')->default(0)->after('followers_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['followers_count', 'following_count']);
        });
    }
};
