<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('availability_patterns', function (Blueprint $table) {
            // Herhaling: elke N weken (op de weekdag) of elke N dagen, geteld vanaf starts_on.
            $table->string('repeat_unit', 10)->default('week')->after('type');
            $table->unsignedSmallInteger('repeat_every')->default(1)->after('repeat_unit');
            $table->date('starts_on')->nullable()->after('repeat_every');
        });
    }

    public function down(): void
    {
        Schema::table('availability_patterns', function (Blueprint $table) {
            $table->dropColumn(['repeat_unit', 'repeat_every', 'starts_on']);
        });
    }
};
