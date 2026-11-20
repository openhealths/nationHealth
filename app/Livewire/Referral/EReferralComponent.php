<?php

declare(strict_types=1);

namespace App\Livewire\Referral;

use App\Dto\ServiceRequest\Form as ReferralFormData;
use App\Livewire\Referral\Forms\EReferralForm;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Repositories\MedicalEvents\Repository;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

/** Shared form state; page access belongs to the route, and mutations authorize their own actions. */
abstract class EReferralComponent extends Component
{
    public EReferralForm $eReferralForm;

    #[Locked]
    public int $legalEntityId;

    #[Locked]
    public int $personId;

    #[Locked]
    public int $referralId;

    protected ?ServiceRequestRequest $referralModel = null;

    protected ?LegalEntity $legalEntity;

    protected ObjectMapper $objectMapper;

    public function boot(): void
    {
        $this->legalEntity = legalEntity();
        // Read Eloquent attributes and loaded relations through their array-access paths.
        $this->objectMapper = new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor());
    }

    protected function initializeReferral(int $legalEntityId, int $personId, int $referralId): void
    {
        $this->legalEntityId = $legalEntityId;
        $this->personId = $personId;
        $this->referralId = $referralId;

        $data = $this->objectMapper->map($this->referral(), ReferralFormData::class);
        $this->eReferralForm->setReferral($data);
    }

    protected function referral(): ServiceRequestRequest
    {
        // A hydrated form still belongs to the facility where it was opened. Check before reading the referral.
        abort_unless($this->legalEntity?->id === $this->legalEntityId, 404);

        return $this->referralModel ??= Repository::serviceRequest()->findForPerson($this->referralId, $this->personId);
    }
}
