<?php

declare(strict_types=1);

use App\Enums\Episode\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Older databases have already recorded the edited migrations; reconcile their metadata in a new step. */
return new class extends Migration
{
    public function up(): void
    {
        $this->alignForeignKey('episode_diagnoses_history_items', 'episode_diagnoses_history_id', 'episode_diagnoses_history', 'episode_diagnoses_history_items_history_fk');
        $this->alignForeignKey('reorganization_employee_declarations', 'declaration_request_id', 'declaration_requests', 'reorg_employee_declarations_request_fk');
        foreach (['person_id' => 'persons', 'declaration_id' => 'declarations'] as $column => $target) {
            $this->alignForeignKey('reorganization_employee_declarations', $column, $target, 'reorganization_employee_declarations_'.$column.'_foreign', cascadeOnUpdate: true);
        }

        foreach ([
            'care_plan_contributors' => ['care_plan_id', 'identifier_id'],
            'care_plan_activity_authors' => ['activity_id', 'identifier_id'],
            'care_plan_activity_reason_codes' => ['activity_id', 'concept_id'],
            'care_plan_activity_goal_concepts' => ['activity_id', 'concept_id'],
            'care_plan_activity_outcome_concepts' => ['activity_id', 'concept_id'],
        ] as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $this->alignIndex($table, $columns, $table.'_owner_value_unique', unique: true);
        }

        $this->alignComments();
        if (Schema::hasTable('episode_status_history')) {
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                Schema::table('episode_status_history', static fn (Blueprint $table) => $table->enum('status', Status::values())->change());
            } else {
                DB::statement('ALTER TABLE episode_status_history DROP CONSTRAINT IF EXISTS episode_status_history_status_check');
                $values = implode("', '", Status::values());
                DB::statement("ALTER TABLE episode_status_history ADD CONSTRAINT episode_status_history_status_check CHECK (status IN ('$values'))");
            }
        }
    }

    protected function alignForeignKey(string $table, string $column, string $target, string $name, bool $cascadeOnUpdate = false): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }
        $foreign = collect(Schema::getForeignKeys($table))->first(static fn (array $foreign): bool => $foreign['columns'] === [$column]);
        if ($foreign !== null && ($foreign['foreign_table'] !== $target || $foreign['foreign_columns'] !== ['id'])) {
            throw new RuntimeException("Unexpected foreign key target on {$table}.{$column}; schema alignment stopped.");
        }
        $onUpdate = $cascadeOnUpdate ? 'cascade' : (DB::getDriverName() === 'pgsql' ? 'no action' : 'restrict');
        if ($foreign === null || $foreign['name'] !== $name || $foreign['on_delete'] !== 'cascade' || $foreign['on_update'] !== $onUpdate) {
            Schema::table($table, static function (Blueprint $blueprint) use ($foreign, $column, $target, $name, $cascadeOnUpdate): void {
                if ($foreign !== null) {
                    $blueprint->dropForeign($foreign['name']);
                }
                $constraint = $blueprint->foreign($column, $name)->references('id')->on($target)->cascadeOnDelete();
                if ($cascadeOnUpdate) {
                    $constraint->cascadeOnUpdate();
                }
            });
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->alignIndex($table, [$column], $name, unique: false);
        }
    }

    protected function alignIndex(string $table, array $columns, string $name, bool $unique): void
    {
        $indexes = collect(Schema::getIndexes($table))->filter(
            static fn (array $index): bool => $index['columns'] === $columns && $index['unique'] === $unique && !$index['primary']
        );
        if ($indexes->contains('name', $name)) {
            return;
        }
        $index = $indexes->first();
        Schema::table($table, static function (Blueprint $blueprint) use ($index, $columns, $name, $unique): void {
            if ($index !== null) {
                $blueprint->renameIndex($index['name'], $name);
            } elseif ($unique) {
                $blueprint->unique($columns, $name);
            } else {
                $blueprint->index($columns, $name);
            }
        });
    }

    protected function alignComments(): void
    {
        foreach ([
            ['client_connections', 'uuid', 'uuid', 'Connection UUID at the eHealth side'],
            ['quantities', 'value', 'decimal', 'Numerical value (with implicit precision)'],
            ['merged_persons', 'merged_uuid', 'uuid', 'eHealth identifier of the person or preperson merged into the identified patient'],
            ['merged_persons', 'merged_preperson_id', 'foreign', 'Preperson merged into the patient, when their record is known locally'],
        ] as [$table, $column, $type, $comment]) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $existing = collect(Schema::getColumns($table))->firstWhere('name', $column);
            if ($existing === null || $existing['comment'] === $comment) {
                continue;
            }
            Schema::table($table, static function (Blueprint $blueprint) use ($column, $type, $comment): void {
                $definition = match ($type) {
                    'uuid' => $blueprint->uuid($column),
                    'decimal' => $blueprint->decimal($column, 24, 10),
                    'foreign' => $blueprint->foreignId($column)->nullable(),
                };
                $definition->comment($comment)->change();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Schema alignment requires a forward fix; previous constraint names and comments depend on the installation path.');
    }
};
