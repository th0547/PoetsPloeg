<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('availability_patterns', function (Blueprint $table) {
            // available = "kan wel", unavailable = "kan niet" (bijv. school, andere baan).
            $table->string('type')->default('available')->after('weekday');
            $table->string('note', 100)->nullable()->after('end_time');
        });
    }

    public function down(): void
    {
        Schema::table('availability_patterns', function (Blueprint $table) {
            $table->dropColumn(['type', 'note']);
        });
    }
};
