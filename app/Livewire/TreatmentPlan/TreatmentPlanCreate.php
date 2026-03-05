<?php

namespace App\Livewire\TreatmentPlan;

use App\Classes\eHealth\Api\CarePlan;
use App\Models\TreatmentPlan;
use App\Repositories\TreatmentPlanRepository;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class TreatmentPlanCreate extends Component
{
    public $person_id;
    public $author_id;
    public $legal_entity_id;
    public $category;
    public $title;
    public $period_start;
    public $period_end;
    public $encounter_id;
    public $addresses = [];
    public $description;
    public $supporting_info = [];
    public $note;
    public $inform_with;

    public function mount()
    {
        $this->period_start = now()->format('Y-m-d');
        // Retrieve context: current professional, legal entity, patient
        // $this->author_id = ...
        // $this->legal_entity_id = ...
    }

    protected $rules = [
        'category' => 'required|string',
        'title' => 'required|string',
        'period_start' => 'required|date',
        'period_end' => 'nullable|date|after_or_equal:period_start',
        'encounter_id' => 'required|integer', // Usually mapped to UUID for eHealth
        // other rules based on TZ
    ];

    public function updatedPeriodEnd()
    {
        // TZ: 3.10.1.2.4 Display warning message if period_end is provided
        if ($this->period_end) {
            $this->dispatch('notify', [
                'type' => 'warning',
                'message' => 'Увага! Ви зазначаєте кінцевий термін періоду дійсності плану лікування. Зауважте, що отримання пацієнтом медичних послуг, медичних виробів або лікарських засобів за призначенням з цього плану лікування після цієї дати будуть неможливі!'
            ]);
        }
    }

    public function save(TreatmentPlanRepository $repository)
    {
        $this->validate();

        // 1. Save drafted record locally (status = NEW)
        $plan = $repository->create([
            'person_id' => $this->person_id,
            'author_id' => $this->author_id,
            'legal_entity_id' => $this->legal_entity_id,
            'category' => $this->category,
            'title' => $this->title,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'encounter_id' => $this->encounter_id,
            'addresses' => $this->addresses,
            'description' => $this->description,
            'supporting_info' => $this->supporting_info,
            'note' => $this->note,
            'inform_with' => $this->inform_with,
            'status' => 'NEW'
        ]);

        // 2. Prepare payload for eHealth synchronization
        $payload = [
            'intent' => 'order',
            // structure the rest of the payload according to specification
        ];

        // This component prepares data; actual signing with KEP happens via frontend component
        // Once signed, dispatch 'carePlanSigned' event to handle EHealth API
        
        $this->dispatch('plan-saved', ['id' => $plan->id]);
    }

    public function render()
    {
        return view('livewire.treatment-plan.treatment-plan-create');
    }
}
