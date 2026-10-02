<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('accounts.users_table', 'users'), function (Blueprint $table) {
            $table->string('accounts_issuer')->nullable();
            $table->string('accounts_sub')->nullable();
            $table->unique(['accounts_issuer', 'accounts_sub']);
        });
    }

    public function down(): void
    {
        Schema::table(config('accounts.users_table', 'users'), function (Blueprint $table) {
            $table->dropUnique(['accounts_issuer', 'accounts_sub']);
            $table->dropColumn(['accounts_issuer', 'accounts_sub']);
        });
    }
};
