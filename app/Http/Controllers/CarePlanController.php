<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Repositories\Repository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class CarePlanController extends Controller
{
    public function __invoke(LegalEntity $legalEntity, Encounter $encounter): RedirectResponse
    {
        $person = Repository::carePlan()->findPatient((int) $encounter->person_id) ?? abort(404);
        Gate::authorize('view', [$encounter, $person]);

        return redirect()->route('persons.encounters.care-plans.create', [
            'legalEntity' => $legalEntity,
            'person' => $person,
            'encounter' => $encounter,
        ]);
    }
}
