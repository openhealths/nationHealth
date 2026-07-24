<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Replaces the redundant preperson_id column and the master_person_id / merge_person_id UUID columns with
     * proper foreign keys: master_person_id references persons (nullable, since the identified patient may not
     * be stored locally) and merge_person_id references prepersons.
     *
     * @return void
     */
    public function up(): void
    {
        // Only the old shape carries preperson_id; a fresh install already creates the final FK columns, so skip.
        if (!Schema::hasColumn('merge_requests', 'preperson_id')) {
            return;
        }

        // Existing rows are eHealth-synced cache that can be re-fetched, so clear them before reshaping the
        // person columns into foreign keys (merge_person_id becomes NOT NULL).
        DB::table('merge_requests')->truncate();

        Schema::table('merge_requests', static function (Blueprint $table): void {
            if (Schema::hasColumn('merge_requests', 'preperson_id')) {
                $table->dropConstrainedForeignId('preperson_id');
            }

            if (Schema::hasColumn('merge_requests', 'master_person_id')) {
                $table->dropColumn('master_person_id');
            }

            if (Schema::hasColumn('merge_requests', 'merge_person_id')) {
                $table->dropColumn('merge_person_id');
            }
        });

        Schema::table('merge_requests', static function (Blueprint $table): void {
            $table->foreignId('master_person_id')
                ->nullable()
                ->after('uuid')
                ->constrained('persons')
                ->comment('Identified patient the preperson is merged into');

            $table->foreignId('merge_person_id')
                ->after('master_person_id')
                ->constrained('prepersons')
                ->comment('Preperson being merged');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        // Nothing to revert when the table is absent or already in the old shape (preperson_id present).
        if (!Schema::hasTable('merge_requests') || Schema::hasColumn('merge_requests', 'preperson_id')) {
            return;
        }

        DB::table('merge_requests')->truncate();

        Schema::table('merge_requests', static function (Blueprint $table): void {
            if (Schema::hasColumn('merge_requests', 'master_person_id')) {
                $table->dropConstrainedForeignId('master_person_id');
            }

            if (Schema::hasColumn('merge_requests', 'merge_person_id')) {
                $table->dropConstrainedForeignId('merge_person_id');
            }
        });

        Schema::table('merge_requests', static function (Blueprint $table): void {
            $table->foreignId('preperson_id')->nullable()->constrained('prepersons');
            $table->uuid('master_person_id')->comment('MPI identifier of the identified patient to merge into');
            $table->uuid('merge_person_id')->comment('MPI identifier of the preperson being merged');
        });
    }
};
