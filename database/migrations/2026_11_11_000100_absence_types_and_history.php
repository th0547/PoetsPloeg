<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absences', function (Blueprint $table) {
            // Ingetrokken afwezigheid blijft bewaard voor de geschiedenis, maar telt nergens meer mee.
            $table->softDeletes();
        });

        // "Verlofverzoek" is geen soort meer: of iets een verzoek is, staat in de status.
        // De soort (verlof, huisarts, verhuisdag, ...) blijft daardoor ook bij een verzoek bewaard.
        DB::table('absences')->where('type', 'verzoek')->update(['type' => 'verlof']);
    }

    public function down(): void
    {
        DB::table('absences')->whereIn('status', ['pending', 'rejected'])->update(['type' => 'verzoek']);

        Schema::table('absences', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
