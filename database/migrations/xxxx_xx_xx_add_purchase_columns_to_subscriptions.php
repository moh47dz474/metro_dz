<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Only add columns that don't already exist
            if (!Schema::hasColumn('subscriptions', 'payment_method')) {
                $table->string('payment_method')->default('cash')->after('status');
            }
            if (!Schema::hasColumn('subscriptions', 'valid_to')) {
                $table->timestamp('valid_to')->nullable()->after('valid_from');
            }
            if (!Schema::hasColumn('subscriptions', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumnIfExists('payment_method');
        });
    }
};