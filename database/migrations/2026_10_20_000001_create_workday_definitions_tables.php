<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = $this->getConnection() ?? config('workdays.database.connection');
        $schema = Schema::connection($connection);
        $schema->create('workday_definition_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('schema_version');
            $table->longText('settings_json');
            $table->char('settings_hash', 64);
            $table->char('content_hash', 64);
            $table->unsignedInteger('profile_count');
            $table->text('metadata_json');
            $table->timestamp('created_at');
        });
        $schema->create('workday_definition_profiles', function (Blueprint $table): void {
            $table->foreignId('revision_id')->constrained('workday_definition_revisions')->restrictOnDelete();
            // Digest identity is exact across SQLite/MySQL collations, including case/accent.
            $table->char('name_hash', 64);
            $table->string('profile_name', 255);
            $table->unsignedInteger('position');
            $table->longText('definition_json');
            $table->char('content_hash', 64);
            $table->primary(['revision_id', 'name_hash']);
            $table->unique(['revision_id', 'position'], 'workday_definition_profile_position');
        });
        $schema->create('workday_definition_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('active_revision_id')->nullable()->constrained('workday_definition_revisions')->restrictOnDelete();
        });
        // Explicit schema migration only: no operational profile is seeded or selected.
        DB::connection($connection)->table('workday_definition_state')->insert(['id' => 1, 'active_revision_id' => null]);
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection() ?? config('workdays.database.connection'));
        $schema->dropIfExists('workday_definition_state');
        $schema->dropIfExists('workday_definition_profiles');
        $schema->dropIfExists('workday_definition_revisions');
    }
};
