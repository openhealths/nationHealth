<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

use App\Enums\DeviceAssociation\Status;
use App\Models\MedicalEvents\Sql\DeviceAssociation;
use App\Rules\InDictionary;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Form;

class DeviceAssociationForm extends Form
{
    public array $deviceAssociations = [];

    protected function rules(): array
    {
        return [
            'deviceAssociations' => [
                'nullable',
                'array',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value && Auth::user()->cannot('create', DeviceAssociation::class)) {
                        $fail(__('device-associations.policy.create'));
                    }
                }
            ],
            'deviceAssociations.*.uuid' => ['nullable', 'uuid'],
            'deviceAssociations.*.deviceId' => ['required_with:deviceAssociations', 'uuid'],
            'deviceAssociations.*.status' => [
                'required_with:deviceAssociations',
                'string',
                new InDictionary('device_association_statuses'),
                // A record is marked as erroneous by cancelling it, so an association is never submitted as one
                Rule::notIn([Status::ENTERED_IN_ERROR->value]),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $this->validateStatus((int) explode('.', $attribute)[1], $fail);
                }
            ],
            // The mapper dates the record when the package is built, so an association being added has no date yet
            'deviceAssociations.*.recorded' => ['nullable', 'date', 'before_or_equal:now'],
            'deviceAssociations.*.associationDate' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $primarySource = $this->deviceAssociations[(int) explode('.', $attribute)[1]]['primarySource']
                        ?? null;
                    $encounterDate = ($this->component->form->encounter['periodDate'] ?? '') ?: 'today';

                    // The encounter lasts a single day, so a date within its period is the encounter date itself
                    return [
                        'nullable',
                        'date',
                        $primarySource === false
                            ? 'before_or_equal:' . $encounterDate
                            : 'date_equals:' . $encounterDate
                    ];
                }
            ),
            'deviceAssociations.*.bodySiteCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/body_structures')
            ],
            // The comment only reaches eHealth as the text of the body site, so it makes no sense without one
            'deviceAssociations.*.bodySiteText' => Rule::forEach(function (mixed $value, string $attribute): array {
                $bodySiteCode = $this->deviceAssociations[(int) explode('.', $attribute)[1]]['bodySiteCode'] ?? '';

                return [$bodySiteCode === '' ? 'prohibited' : 'nullable', 'string'];
            }),
            'deviceAssociations.*.primarySource' => ['required_with:deviceAssociations', 'boolean'],
            'deviceAssociations.*.reportOriginCode' => Rule::forEach(function (mixed $value, string $attribute) {
                $primarySource = $this->deviceAssociations[(int) explode('.', $attribute)[1]]['primarySource']
                    ?? null;

                return [
                    Rule::requiredIf($primarySource === false),
                    $primarySource === true ? 'prohibited' : 'nullable',
                    'string',
                    new InDictionary('eHealth/report_origins')
                ];
            }),
            'deviceAssociations.*.reportOriginText' => ['nullable', 'string']
        ];
    }

    /**
     * The default wording only says the comment is prohibited, which leaves out what to do about it.
     *
     * @return array
     */
    protected function messages(): array
    {
        return [
            'deviceAssociations.*.bodySiteText.prohibited'
                => __('device-associations.validation.body_site_comment_requires_body_site')
        ];
    }

    /**
     * Name the fields of an association the way the form labels them.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        return collect(__('device-associations.attributes'))
            ->mapWithKeys(static fn (string $name, string $field): array => ["deviceAssociations.*.$field" => $name])
            ->all();
    }

    /**
     * A device is either registered by this very package or was registered before, and each case allows the
     * association a different set of statuses.
     *
     * @param  int  $index  Index of the association within the package
     * @param  Closure  $fail
     * @return void
     */
    private function validateStatus(int $index, Closure $fail): void
    {
        $association = $this->deviceAssociations[$index] ?? [];
        $deviceId = $association['deviceId'] ?? '';
        $status = Status::tryFrom($association['status'] ?? '');

        if (!$deviceId || $status === null || $status === Status::ENTERED_IN_ERROR) {
            return;
        }

        $otherAssociations = collect($this->deviceAssociations)
            ->forget($index)
            ->filter(static fn (array $other): bool => ($other['deviceId'] ?? '') === $deviceId)
            ->values()
            ->toArray();

        $isDeviceInPackage = collect($this->component->deviceForm->devices)->contains(
            static fn (array $device): bool => ($device['uuid'] ?? '') === $deviceId
        );

        if ($isDeviceInPackage) {
            $this->validatePackageStatus($association, $status, $otherAssociations, $fail);

            return;
        }

        $this->validateExistingStatus($association, $status, $otherAssociations, $fail);
    }

    /**
     * A device registered by this package may be associated twice: once as the association opening the connection
     * and once as the association closing it.
     *
     * @param  array  $association
     * @param  Status  $status
     * @param  array  $otherAssociations  Associations of the same device elsewhere in the package
     * @param  Closure  $fail
     * @return void
     */
    private function validatePackageStatus(
        array $association,
        Status $status,
        array $otherAssociations,
        Closure $fail
    ): void {
        if (count($otherAssociations) > 1) {
            $fail(__('device-associations.validation.max_two_in_package'));

            return;
        }

        $other = $otherAssociations[0] ?? [];
        $otherStatus = Status::tryFrom($other['status'] ?? '');

        $closingStatus = match ($status) {
            Status::IMPLANTED => Status::EXPLANTED,
            Status::ATTACHED => Status::UNATTACHED,
            default => null
        };

        if ($closingStatus !== null) {
            if ($other === []) {
                return;
            }

            if ($otherStatus !== $closingStatus) {
                $fail($status === Status::IMPLANTED
                    ? __('device-associations.validation.implanted_pair_invalid')
                    : __('device-associations.validation.attached_pair_invalid'));

                return;
            }

            $recorded = $association['recorded'] ?? '';
            $closingRecorded = $other['recorded'] ?? '';

            // Both are dated when the package is built, so the order is only checked once it is known
            if (
                $recorded && $closingRecorded
                && CarbonImmutable::parse($closingRecorded)->lessThanOrEqualTo(CarbonImmutable::parse($recorded))
            ) {
                $fail($status === Status::IMPLANTED
                    ? __('device-associations.validation.implanted_recorded_after_explanted')
                    : __('device-associations.validation.attached_recorded_after_unattached'));
            }

            return;
        }

        $openingStatus = $status === Status::EXPLANTED ? Status::IMPLANTED : Status::ATTACHED;

        if ($otherStatus !== $openingStatus) {
            $fail($status === Status::EXPLANTED
                ? __('device-associations.validation.implanted_required')
                : __('device-associations.validation.attached_required'));
        }
    }

    /**
     * A device registered before may be associated once per package, and the association continues the one it was last given.
     *
     * @param  array  $association
     * @param  Status  $status
     * @param  array  $otherAssociations  Associations of the same device elsewhere in the package
     * @param  Closure  $fail
     * @return void
     */
    private function validateExistingStatus(
        array $association,
        Status $status,
        array $otherAssociations,
        Closure $fail
    ): void {
        if ($otherAssociations !== []) {
            $fail(__('device-associations.validation.max_one_in_package'));

            return;
        }

        if ($status === Status::IMPLANTED) {
            $fail(__('device-associations.validation.implanted_not_allowed'));

            return;
        }

        $lastAssociation = DeviceAssociation::query()
            ->whereHas(
                'device',
                static fn (Builder $device): Builder => $device->whereValue($association['deviceId'])
            )
            ->whereNot('status', Status::ENTERED_IN_ERROR)
            // A package saved as a draft is already stored, so the association continues the one before its own
            ->whereNot('uuid', $association['uuid'] ?? '')
            ->orderByDesc('recorded')
            ->first(['status']);

        if ($lastAssociation === null) {
            $fail(__('device-associations.validation.last_not_found'));

            return;
        }

        [$requiredStatus, $message] = match ($status) {
            Status::EXPLANTED => [
                Status::IMPLANTED,
                'device-associations.validation.last_must_be_implanted'
            ],
            Status::ATTACHED => [
                Status::UNATTACHED,
                'device-associations.validation.last_must_be_unattached'
            ],
            default => [
                Status::ATTACHED,
                'device-associations.validation.last_must_be_attached'
            ]
        };

        if ($lastAssociation->status !== $requiredStatus) {
            $fail(__($message));
        }
    }
}
