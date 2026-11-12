<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beleid met versies (issue #42). Alleen afwijkingen van de cao-standaard (config/poetsploeg-cao.php)
 * worden opgeslagen. Elke versie geldt vanaf valid_from tot de volgende.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_versions', function (Blueprint $table) {
            $table->id();
            $table->date('valid_from')->unique();
            $table->json('settings'); // groep.sleutel => waarde (alleen afwijkingen)
            $table->json('leave_types'); // sleutel => velden (afwijkingen en eigen soorten)
            $table->text('note')->nullable(); // toelichting (verplicht bij afwijking ten nadele)
            $table->json('employee_notes')->nullable(); // toelichting per instelling voor medewerkers
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_versions');
    }
};
