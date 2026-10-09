<?php

namespace App\Livewire\Encounter;

use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use Livewire\Component;
use Livewire\Attributes\Layout;

class EncounterReferralCreate extends Component
{
    public Encounter $encounter;
    public Person $person;
    public string $patientFullName = '';
    public ?int $personId = null;
    public ?int $prepersonId = null;
    public ?int $legalEntityId = null;
    
    /** @var list<array{id: string, name: string}> */
    public array $encounterReferralPrograms = [];
    
    public array $form = [
        'service_id' => '',
        'service_name' => '',
        'quantity' => 5,
        'quantity_unit' => 'шт',
        'category' => 'consultation',
        'priority' => 'routine',
        'date_type' => 'period',
        'started_at' => '',
        'ended_at' => '',
        'program_id' => '',
        'doctor_name' => '',
        'provider_name' => '',
        'note_doctor' => '',
        'note_patient' => '',
        'supportingInfo' => [],
        'reasonReference' => [],
    ];

    public bool $showServiceSearchDrawer = false;
    public string $searchQuery = '';
    public array $searchResults = [];
    public int $searchPage = 1;

    public function searchServices(): void
    {
        if (empty($this->searchQuery)) {
            $this->searchResults = [];
            return;
        }

        try {
            $this->searchResults = \App\Services\Dictionary\ServiceSearch::search(
                trim($this->searchQuery),
                static fn (array $params): array => \App\Classes\eHealth\EHealth::service()->getMany($params)->getData(),
                $this->searchPage
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to search services: " . $e->getMessage());
            $this->searchResults = [];
        }
    }

    public function selectProduct(array $service, string $type): void
    {
        $this->form['service_id'] = $service['id'] ?? '';
        $this->form['service_name'] = $service['name'] ?? '';
        $this->showServiceSearchDrawer = false;
    }

    public function mount(\App\Models\LegalEntity $legalEntity, int $encounterId, ?Person $person = null, ?\App\Models\Preperson $preperson = null)
    {
        $this->legalEntityId = $legalEntity->id;
        $this->encounter = Encounter::findOrFail($encounterId);
        $this->person = Person::findOrFail($this->encounter->person_id);
        $this->patientFullName = $this->person->full_name ?? $this->person->fullName ;
        
        $this->personId = $person ? $person->id : $this->person->id;
        $this->prepersonId = $preperson ? $preperson->id : null;
        
        $this->form['doctor_name'] = auth()->user()->name ;
        $this->form['provider_name'] = $legalEntity->name ;
        
        $this->form['supportingInfo'] = [];
        $this->form['reasonReference'] = [];
        
        $this->loadEncounterReferralPrograms();
    }
    
    protected function loadEncounterReferralPrograms(): void
    {
        try {
            $this->encounterReferralPrograms = dictionary()->medicalPrograms()
                ->where('is_active', true)
                ->where('type', \App\Enums\MedicalEvents\MedicalProgramType::SERVICE->value)
                ->map(static fn (array $program): array => [
                    'id' => (string) ($program['id'] ?? ''),
                    'name' => (string) ($program['name'] ?? ''),
                ])
                ->filter(static fn (array $program): bool => $program['id'] !== '' && $program['name'] !== '')
                ->values()
                ->all();
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('EncounterReferralCreate: failed to load programs: '.$exception->getMessage());
        }
    }

    
    public function save()
    {
        if ($this->prepersonId) {
            return redirect()->route('prepersons.encounter.referral.review', [
                'legalEntity' => $this->legalEntityId,
                'preperson' => $this->prepersonId,
                'encounterId' => $this->encounter->id
            ]);
        }
        
        return redirect()->route('encounter.referral.review', [
            'legalEntity' => $this->legalEntityId,
            'person' => $this->personId,
            'encounterId' => $this->encounter->id
        ]);
    }

    public function render()
    {
        return view('livewire.encounter.encounter-referral-create');
    }
}
