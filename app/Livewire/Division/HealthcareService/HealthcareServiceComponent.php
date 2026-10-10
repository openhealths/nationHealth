<?php

declare(strict_types=1);

namespace App\Livewire\Division\HealthcareService;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Dto\HealthcareService\Ehealth as EhealthData;
use App\Livewire\Division\Forms\HealthcareServiceForm as Form;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Traits\FormTrait;
use App\Traits\WorkTimeUtilities;
use GuzzleHttp\Promise\PromiseInterface;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class HealthcareServiceComponent extends Component
{
    use FormTrait;
    use WorkTimeUtilities;

    public Form $form;

    public string $divisionName;

    #[Locked]
    public int $divisionId;

    public Collection $licenses;

    public bool $working = false;

    /**
     * Used to indicate is it edit page, if so update DB row instead of create new one.
     *
     * @var int|null
     */
    #[Locked]
    public ?int $healthcareServiceId = null;

    /**
     * Is in view mode.
     *
     * @var bool
     */
    public bool $isDisabled = false;

    protected array $dictionaryNames = [
        'HEALTHCARE_SERVICE_CATEGORIES',
        'SPECIALITY_TYPE',
        'PROVIDING_CONDITION',
        'HEALTHCARE_SERVICE_PHARMACY_DRUGS_TYPES'
    ];

    public function baseMount(LegalEntity $legalEntity, Division $division): void
    {
        $this->getDictionary();

        $this->dictionaries['HEALTHCARE_SERVICE_CATEGORIES'] = $this->getDictionariesFields(
            config('ehealth.healthcare_service_' . strtolower(legalEntity()->type->name) . '_categories', []),
            'HEALTHCARE_SERVICE_CATEGORIES'
        );
        $this->dictionaries['PROVIDING_CONDITION'] = $this->getDictionariesFields(
            config('ehealth.legal_entity_' . strtolower(legalEntity()->type->name) . '_providing_conditions', []),
            'PROVIDING_CONDITION'
        );

        $this->divisionName = $division->name;
        $this->form->divisionId = $division->uuid;
        $this->divisionId = $division->id;

        $this->licenses = $legalEntity->licenses()->get(['id', 'uuid', 'type']);
    }

    /**
     * Categories that require each conditional field, consumed by the frontend to toggle field visibility.
     *
     * @return array
     */
    #[Computed]
    public function categoryRequiredFields(): array
    {
        $categoryCodes = array_keys($this->dictionaries['HEALTHCARE_SERVICE_CATEGORIES'] ?? []);

        return [
            'speciality' => config('ehealth.healthcare_service_speciality_type_field_required_for_categories', []),
            'providingCondition' => config('ehealth.healthcare_service_providing_condition_field_required_for_categories', []),
            'type' => config('ehealth.healthcare_service_type_field_required_for_categories', []),
            'license' => array_values(
                array_filter(
                    $categoryCodes,
                    static fn (string $categoryCode): bool => filled(
                        config('ehealth.healthcare_service_' . strtolower($categoryCode) . '_license_type')
                    )
                )
            )
        ];
    }

    /**
     * Validate form; on failure flash the first error and fill the error bag.
     *
     * @return bool
     */
    protected function validateForm(): bool
    {
        try {
            $this->form->doValidation();

            return true;
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return false;
        }
    }

    /**
     * Send a request to the API; if successful, return it; otherwise, show and log errors.
     *
     * @return EHealthResponse|PromiseInterface|null
     */
    protected function createInEHealth(): EHealthResponse|PromiseInterface|null
    {
        $healthcareServiceEhealthMapped = EhealthData::fromForm($this->form)->toArray();

        try {
            return EHealth::healthcareService()->create($healthcareServiceEhealthMapped);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when creating a healthcare service');

            return null;
        }
    }
}
