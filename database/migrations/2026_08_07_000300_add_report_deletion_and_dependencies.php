<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_generations', function (Blueprint $table): void {
            $table->softDeletesTz();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('deletion_reason')->nullable();
            $table->timestampTz('purge_after')->nullable()->index();
        });

        Schema::create('report_generation_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_generation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_generation_id')->constrained('report_generations')->restrictOnDelete();
            $table->string('dependency_key');
            $table->timestampsTz();
            $table->unique(['report_generation_id', 'depends_on_generation_id']);
            $table->index('depends_on_generation_id');
        });

        Schema::create('report_deletion_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('report_generation_id')->nullable()->index();
            $table->uuid('report_uuid')->index();
            $table->string('report_code');
            $table->date('reporting_period_start')->nullable();
            $table->date('reporting_period_end')->nullable();
            $table->unsignedBigInteger('original_owner_id')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('deletion_reason')->nullable();
            $table->jsonb('dependent_report_uuids')->nullable();
            $table->timestampTz('deleted_at');
            $table->timestampTz('purge_after')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_deletion_audits');
        Schema::dropIfExists('report_generation_dependencies');
        Schema::table('report_generations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn(['deleted_at', 'deletion_reason', 'purge_after']);
        });
    }
};
