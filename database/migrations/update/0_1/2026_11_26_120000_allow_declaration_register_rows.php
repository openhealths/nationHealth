<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->setNullable(true);
    }

    public function down(): void
    {
        // Requires register-only declarations to have been enriched by a full sync first.
        $this->setNullable(false);
    }

    private function setNullable(bool $nullable): void
    {
        $columns = collect(Schema::getColumns('declarations'))
            ->whereIn('name', ['declaration_request_id', 'person_id', 'signed_at'])
            ->whereStrict('nullable', !$nullable);

        if ($columns->isEmpty()) {
            return;
        }

        Schema::table('declarations', static function (Blueprint $table) use ($columns, $nullable): void {
            foreach ($columns as $column) {
                $name = $column['name'];
                $definition = $name === 'signed_at' ? $table->dateTime($name) : $table->foreignId($name);
                $definition->nullable($nullable)->change();
            }
        });
    }
};
