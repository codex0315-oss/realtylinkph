<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->enum('applicant_type', ['salesperson', 'broker'])->nullable()->after('user_id');
            $table->string('accreditation_doc')->nullable()->after('license_doc'); // salesperson accreditation (front)
            $table->string('valid_id')->nullable()->after('accreditation_doc');     // salesperson valid ID
            $table->string('supervising_broker')->nullable()->after('valid_id');    // optional, salesperson only
            $table->text('ai_comment')->nullable()->after('admin_note');            // RealtyLink AI advisory note
            $table->timestamp('ai_assessed_at')->nullable()->after('ai_comment');
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'applicant_type',
                'accreditation_doc',
                'valid_id',
                'supervising_broker',
                'ai_comment',
                'ai_assessed_at',
            ]);
        });
    }
};
