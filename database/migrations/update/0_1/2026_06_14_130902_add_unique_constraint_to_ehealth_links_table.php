<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function uniqueExists(): bool
    {
        return Schema::hasIndex('ehealth_links', 'ehealth_links_linkable_job_unique');
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!$this->uniqueExists()) {
            Schema::table('ehealth_links', function (Blueprint $table) {
                $table->unique(['linkable_type', 'linkable_id', 'ehealth_job_id'], 'ehealth_links_linkable_job_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->uniqueExists()) {
            Schema::table('ehealth_links', function (Blueprint $table) {
                $table->dropUnique('ehealth_links_linkable_job_unique');
            });
        }
    }


};
