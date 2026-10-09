<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->references() as $column => $target) {
            if (!Schema::hasColumn('service_request_requests', $column)) {
                Schema::table('service_request_requests', static function (Blueprint $table) use ($column, $target): void {
                    $table->foreignId($column)->nullable()->constrained($target);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->references() as $column => $target) {
            if (Schema::hasColumn('service_request_requests', $column)) {
                Schema::table('service_request_requests', static function (Blueprint $table) use ($column): void {
                    $table->dropConstrainedForeignId($column);
                });
            }
        }
    }

    /** @return array<string, string> */
    private function references(): array
    {
        return [
            'performer_id' => 'identifiers',
            'location_reference_id' => 'identifiers',
            'performer_type_id' => 'codeable_concepts',
        ];
    }
};
