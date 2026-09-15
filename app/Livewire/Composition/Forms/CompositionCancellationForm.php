<?php

declare(strict_types=1);

namespace App\Livewire\Composition\Forms;

use App\Core\BaseForm;
use App\Enums\Person\CompositionType;

/**
 * Marking a Composition (МВН / МВТН) as entered in error.
 *
 * TV 3.8.1.10.3 and 3.8.2.15.3 require both a reason and a written justification, which
 * maps onto the two halves of the signed `reason` object: a dictionary code in
 * `coding`, and the doctor's own wording in `text`.
 */
class CompositionCancellationForm extends BaseForm
{
    /** Code from the type's COMPOSITION_CANCELLATION_REASONS_* dictionary. */
    public string $reason = '';

    /** The author's written justification for the cancellation. */
    public string $reasonText = '';

    /**
     * @param  array<string, string>  $allowedReasons  Codes valid for this conclusion type.
     */
    public function cancellationRules(array $allowedReasons = []): array
    {
        return [
            'reason' => array_filter([
                'required',
                'string',
                $allowedReasons === [] ? null : 'in:' . implode(',', array_keys($allowedReasons)),
            ]),
            'reasonText' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }

    /**
     * Build the object that gets signed and sent to cancelComposition.
     *
     * @return array<string, mixed>
     */
    public function toCancellationPayload(string $compositionUuid, CompositionType $type): array
    {
        return [
            'identifier' => [
                'type' => [
                    'coding' => [
                        [
                            'system' => 'eHealth/resources',
                            'code' => 'composition',
                        ],
                    ],
                ],
                'value' => $compositionUuid,
            ],
            'reason' => [
                'coding' => [
                    [
                        'system' => 'eHealth/' . $type->cancellationReasonDictionary(),
                        'code' => $this->reason,
                    ],
                ],
                'text' => $this->reasonText,
            ],
        ];
    }

    public function resetCancellationFields(): void
    {
        $this->reason = '';
        $this->reasonText = '';
    }

    protected function rules(): array
    {
        return $this->cancellationRules();
    }
}
