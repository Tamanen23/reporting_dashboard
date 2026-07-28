<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('definition_version');
            $table->string('calculation_version');
            $table->string('template_version');
            $table->string('processor_identifier');
            $table->string('template_identifier');
            $table->string('reporting_period_type');
            $table->jsonb('supported_outputs');
            $table->jsonb('configuration')->nullable();
            $table->jsonb('allowed_roles')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->unsignedInteger('timeout_seconds')->default(900);
            $table->unsignedInteger('retention_days')->default(365);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('report_input_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_definition_id')->constrained()->cascadeOnDelete();
            $table->string('input_key');
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(true);
            $table->jsonb('accepted_extensions');
            $table->jsonb('required_columns');
            $table->jsonb('validation_rules')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->unique(['report_definition_id', 'input_key']);
        });

        Schema::create('report_generations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('report_definition_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('reporting_date')->nullable();
            $table->date('reporting_period_start')->nullable();
            $table->date('reporting_period_end')->nullable();
            $table->string('status')->index();
            $table->string('current_stage')->nullable()->index();
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->string('definition_version');
            $table->string('calculation_version');
            $table->string('template_version');
            $table->string('application_version');
            $table->string('engine_version')->nullable();
            $table->char('input_fingerprint', 64)->index();
            $table->text('notes')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('last_progress_at')->nullable()->index();
            $table->unsignedInteger('warnings_count')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('processing_metadata')->nullable();
            $table->timestampsTz();
            $table->index(['report_definition_id', 'reporting_date']);
        });

        Schema::create('report_generation_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_generation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_input_definition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('input_key');
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('storage_disk');
            $table->text('stored_path');
            $table->string('mime_type');
            $table->string('extension', 20);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256_checksum', 64);
            $table->unsignedBigInteger('row_count')->nullable();
            $table->unsignedInteger('column_count')->nullable();
            $table->string('detected_encoding')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->unique(['report_generation_id', 'input_key']);
        });

        Schema::create('report_generation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_generation_id')->constrained()->cascadeOnDelete();
            $table->string('stage')->nullable()->index();
            $table->string('level')->index();
            $table->string('event_code')->index();
            $table->text('message');
            $table->jsonb('context')->nullable();
            $table->timestampTz('occurred_at')->index();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('report_generation_outputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_generation_id')->constrained()->cascadeOnDelete();
            $table->string('output_type')->index();
            $table->string('storage_disk');
            $table->text('stored_path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256_checksum', 64);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->unique(['report_generation_id', 'output_type', 'stored_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_generation_outputs');
        Schema::dropIfExists('report_generation_events');
        Schema::dropIfExists('report_generation_files');
        Schema::dropIfExists('report_generations');
        Schema::dropIfExists('report_input_definitions');
        Schema::dropIfExists('report_definitions');
    }
};
