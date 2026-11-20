<?php

declare(strict_types=1);

namespace App\Dto\ServiceRequest;

use App\Contracts\Dto\Model as ModelContract;
use App\Livewire\Referral\Forms\EReferralForm;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Editable local draft attributes; references and quantities remain owned by the persisted model. */
#[Map(source: EReferralForm::class)]
class Model implements ModelContract
{
    #[Map(source: 'note')]
    public ?string $note = null;

    #[Map(source: 'patientInstruction')]
    public ?string $patientInstruction = null;

    #[Map(source: 'priority')]
    public string $priority = 'routine';

    public function toModel(?ServiceRequestRequest $request = null): ServiceRequestRequest
    {
        $request ??= new ServiceRequestRequest();
        $request->note = $this->note;
        $request->patientInstruction = $this->patientInstruction;

        return $request;
    }
}
