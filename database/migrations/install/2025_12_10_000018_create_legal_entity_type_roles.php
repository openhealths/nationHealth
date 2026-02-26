<?php

use App\Models\Role;
use App\Models\LegalEntityType;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('legal_entity_type_roles', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_type_id')->constrained()->cascadeOnDelete();

            $table->primary(['role_id', 'legal_entity_type_id']);

            $table->timestamps();

            $table->unique(['role_id', 'legal_entity_type_id']);
        });

        try {
            $this->setData();
        } catch (Exception $error) {
            $this->down();

            throw $error;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_entity_type_roles');
    }

    /**
     * Set up the legal entity type roles in the database.
     *
     * @return void
     */
    protected function setData(): void
    {
        $now = now();

        $roleAndTypePairs = [];

        $availableRoles = Role::get(['id', 'name'])
                ->groupBy('name')
                ->map(fn ($group) => $group->pluck('id')->values()->all())
                ->toArray();

        $roleNames = array_keys($availableRoles);

        $legalEntityTypes = LegalEntityType::all()->keyBy('name')->toArray();

        foreach (config('ehealth.legal_entity_employee_types') as $legalEntityType => $roles) {

            if (!isset($legalEntityTypes[$legalEntityType])) {
                echo "\nLegal entity type '{$legalEntityType}' from 'legal_entity_employee_types' config parameter not found in the database.\n";
                continue;
            }

            foreach ($roles as $role) {
                if (in_array($role, $roleNames, true)) {
                    $legalEtntityTypeId = $legalEntityTypes[$legalEntityType]['id'];

                    foreach ($availableRoles[$role] as $roleId) {
                        $roleAndTypePairs[] = [
                            'legal_entity_type_id' => $legalEtntityTypeId,
                            'role_id' => $roleId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                } else {
                    echo "ERROR: Role '{$role}' not found in the configuration.";
                }
            }
        }

        DB::table('legal_entity_type_roles')->insert($roleAndTypePairs);
    }
};
