<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\Cipher\Exceptions\CipherApiException;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Mappers\ConditionMapper;
use App\Services\MedicalEvents\Mappers\DiagnosticReportMapper;
use App\Services\MedicalEvents\Mappers\EncounterMapper;
use App\Services\MedicalEvents\Mappers\ImmunizationMapper;
use App\Services\MedicalEvents\Mappers\ObservationMapper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use JsonException;
use Livewire\Attributes\Locked;
use Throwable;

class EncounterEdit extends EncounterComponent
{
    #[Locked]
    public int $encounterId;

    public function mount(LegalEntity $legalEntity, int $personId, int $encounterId): void
    {
        $this->initializeComponent($personId);
        $this->encounterId = $encounterId;

        $encounter = Encounter::withRelationships()->whereId($encounterId)->firstOrFail()->toArray();

        $this->form->encounter = app(EncounterMapper::class)->fromFhir($encounter);

        $episodeUuid = data_get($encounter, 'episode.identifier.value', '');

        $this->episodeType = 'existing';
        $this->form->episode['id'] = $episodeUuid;

        $this->form->conditions = [];
        $this->form->immunizations = [];
        $this->form->diagnosticReports = [];
        $this->form->observations = [];

        //        $this->form->procedures = Repository::procedure()->get($this->encounterId);
        //        $this->form->procedures = Repository::procedure()->formatForView($this->form->procedures);
        //
        //        $this->form->clinicalImpressions = Repository::clinicalImpression()->get($this->encounterId);
    }

    /**
     * Validate and update data.
     *
     * @return array|null
     */
    public function save(): ?array
    {
        Session::flash('success', __('patients.messages.encounter_updated'));

        return [];
    }

    /**
     * Rename 'id' to 'uuid' and convert keys to snake_case for sync methods.
     *
     * @param  array  $fhirItem
     * @return array
     */
    private function fhirToSync(array $fhirItem): array
    {
        return Arr::toSnakeCase(
            collect($fhirItem)->put('uuid', $fhirItem['id'])->forget(['id'])->all()
        );
    }

    /**
     * Submit encrypted data about person encounter.
     *
     * @return void
     */
    public function sign(): void
    {
        Session::flash('success', 'Дані успішно підписано (симуляція)');
        $this->redirectRoute('persons.index', [legalEntity()], navigate: true);
    }
}
