<?php

declare(strict_types=1);

namespace App\Livewire\Person\Forms;

use App\Core\BaseForm;
use App\Enums\Person\VerificationStatus;
use Illuminate\Validation\Rule;

class PersonVerificationForm extends BaseForm
{
    /**
     * Reason the fact of the person death is confirmed with.
     *
     * @var string
     */
    public const string REASON_MANUAL_DECEASED = 'MANUAL_DECEASED';

    /**
     * Reason the fact of the person death is refuted with.
     *
     * @var string
     */
    public const string REASON_MANUAL_NO_DEATH_RECORD = 'MANUAL_NO_DEATH_RECORD';

    /**
     * Reasons the fact of the person death can be confirmed or refuted with.
     *
     * @var array
     */
    public const array DRACS_DEATH_REASONS = [self::REASON_MANUAL_DECEASED, self::REASON_MANUAL_NO_DEATH_RECORD];

    public string $verificationStatus = VerificationStatus::VERIFIED->value;

    public string $verificationReason = self::REASON_MANUAL_DECEASED;

    public string $verificationComment = '';

    public string $deathDate = '';

    /**
     * Name the fields of the modal the way they are labeled for the user.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        return [
            'verificationStatus' => __('forms.status.label'),
            'verificationReason' => __('patient-verifications.reason_field'),
            'verificationComment' => __('forms.comment'),
            'deathDate' => __('patient-verifications.death_date')
        ];
    }

    /**
     * Rules of the DRACS death verification status update.
     *
     * @return array
     */
    public function rulesForDracsDeath(): array
    {
        return [
            'verificationStatus' => ['required', Rule::in([VerificationStatus::VERIFIED->value])],
            'verificationReason' => ['required', Rule::in(self::DRACS_DEATH_REASONS)],
            'verificationComment' => ['required', 'string', 'max:3000'],
            'deathDate' => [
                'nullable',
                'prohibited_if:verificationReason,' . self::REASON_MANUAL_NO_DEATH_RECORD,
                'date_format:' . config('app.date_format'),
                'before_or_equal:today'
            ]
        ];
    }
}
