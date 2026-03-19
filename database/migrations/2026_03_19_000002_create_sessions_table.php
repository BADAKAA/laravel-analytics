<?php

use App\Enums\Channel;
use App\Enums\DeviceType;
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
        Schema::create('sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->char('visitor_id', 64);

            $table->timestamp('started_at');
            $table->unsignedSmallInteger('duration')->nullable();

            $table->unsignedSmallInteger('pageviews')->default(1);
            $table->boolean('is_bounce')->default(true);

            $table->string('entry_page', 2048);
            $table->string('exit_page', 2048);

            $table->string('utm_source', 255)->nullable();
            $table->string('utm_medium', 255)->nullable();
            $table->string('utm_campaign', 255)->nullable();
            $table->string('utm_content', 255)->nullable();
            $table->string('utm_term', 255)->nullable();
            $table->string('referrer', 2048)->nullable();
            $table->string('referrer_domain', 255)->nullable();

            $table->tinyInteger('channel')->default(Channel::Unknown->value);

            $table->char('country_code', 2)->nullable();
            $table->string('subdivision_code', 10)->nullable();
            $table->string('city', 100)->nullable();

            $table->string('browser', 50)->nullable();
            $table->string('browser_version', 20)->nullable();
            $table->string('os', 50)->nullable();
            $table->string('os_version', 20)->nullable();

            $table->tinyInteger('device_type')->default(DeviceType::Unknown->value);

            $table->unsignedSmallInteger('screen_width')->nullable();

            $table->index(['site_id', 'started_at']);
            $table->index(['site_id', 'channel']);
            $table->index(['site_id', 'country_code']);
            $table->index(['site_id', 'browser']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
