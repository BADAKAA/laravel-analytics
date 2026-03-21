<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            $table->unsignedInteger('visitors')->default(0);
            $table->unsignedInteger('visits')->default(0);
            $table->unsignedInteger('pageviews')->default(0);
            $table->decimal('views_per_visit', 6, 2)->nullable();
            $table->decimal('bounce_rate', 5, 2)->nullable();
            $table->unsignedSmallInteger('avg_duration')->nullable();

            $table->json('channels_agg')->nullable();
            $table->json('referrers_agg')->nullable();
            $table->json('utm_sources_agg')->nullable();
            $table->json('utm_mediums_agg')->nullable();
            $table->json('utm_campaigns_agg')->nullable();
            $table->json('utm_contents_agg')->nullable();
            $table->json('utm_terms_agg')->nullable();

            $table->json('top_pages_agg')->nullable();
            $table->json('entry_pages_agg')->nullable();
            $table->json('exit_pages_agg')->nullable();

            $table->json('countries_agg')->nullable();
            $table->json('regions_agg')->nullable();
            $table->json('cities_agg')->nullable();

            $table->json('browsers_agg')->nullable();
            $table->json('os_agg')->nullable();
            $table->json('devices_agg')->nullable();

            $table->timestamps();
            $table->unique(['site_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
