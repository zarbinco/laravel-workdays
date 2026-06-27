<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workday_special_dates', function (Blueprint $table): void {
            $table->id();
            $table->string('profile')->index();
            $table->date('date')->index();
            $table->string('type')->index();
            $table->string('title')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['profile', 'date', 'type'], 'workday_special_dates_unique_date_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workday_special_dates');
    }
};
