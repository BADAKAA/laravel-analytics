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
        Schema::dropIfExists('pageviews');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('pageviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('sessions')->cascadeOnDelete();

            $table->string('hostname', 255)->nullable();
            $table->string('pathname', 2048);
            $table->timestamp('viewed_at');

            $table->boolean('is_entry')->default(false);
            $table->boolean('is_exit')->default(true);

            $table->index(['site_id', 'viewed_at']);
            if (DB::getDriverName() === 'mysql') {
                DB::statement('CREATE INDEX pageviews_site_id_pathname_index ON pageviews (site_id, pathname(191))');
            } else {
                $table->index(['site_id', 'pathname'], 'pageviews_site_id_pathname_index');
            }
        });
    }
};
