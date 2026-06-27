<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workday_holiday_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('profile')->index();
            $table->string('calendar_type')->index();
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('day');
            $table->string('title')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['profile', 'calendar_type']);
            $table->unique(['profile', 'calendar_type', 'month', 'day'], 'workday_holiday_rules_unique_rule');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workday_holiday_rules');
    }
};
