<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Enums\JobStatus;
use App\Enums\Person\ApprovalStatus;
use App\Enums\Person\AuthenticationMethod;
use App\Enums\Specimen\Status as SpecimenStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\EhealthLink;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\ClinicalImpression;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\Device;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Immunization;
use App\Models\MedicalEvents\Sql\Observation;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Models\Person\Person;
use App\Models\Relations\AuthenticationMethod as AuthenticationMethodModel;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PatientApprovalService
{
    public const array RESOURCE_MODELS = [
        'episode_of_care' => Episode::class,
        'diagnostic_report' => DiagnosticReport::class,
        'encounter' => Encounter::class,
        'procedure' => Procedure::class,
        CarePlan::RESOURCE_TYPE => CarePlan::class,
        'specimen' => Specimen::class,
        'service_request' => ServiceRequestRequest::class,
    ];

    public const array CHILD_MODELS = [
        'encounter' => Encounter::class,
        'diagnostic_report' => DiagnosticReport::class,
        'procedure' => Procedure::class,
        'condition' => Condition::class,
        'observation' => Observation::class,
        'activity' => CarePlanActivity::class,
        'clinical_impression' => ClinicalImpression::class,
        'immunization' => Immunization::class,
        'device' => Device::class,
    ];

    public const array ALLOWED_STATUSES = [
        'episode_of_care' => ['active', 'closed'],
        'diagnostic_report' => ['final'],
        'encounter' => ['finished'],
        'procedure' => ['completed', 'not_done'],
        'specimen' => [SpecimenStatus::AVAILABLE->value],
        'observation' => ['valid'],
        'clinical_impression' => ['completed'],
        'immunization' => ['completed'],
        'device' => ['active', 'inactive'],
    ];

    private ?array $resourceTypeNames = null;

    public function resourceRelations(string $type): array
    {
        return match ($type) {
            'diagnostic_report' => ['code', 'effectivePeriod'],
            'procedure' => ['code', 'performedPeriod'],
            'episode_of_care' => ['period'],
            'encounter' => ['type.coding', 'period'],
            'specimen' => ['type.coding', 'collection.collectedPeriod'],
            'condition', 'observation', 'clinical_impression' => ['code.coding'],
            'immunization' => ['vaccineCode.coding'],
            'device' => ['type.coding'],
            'activity' => ['carePlan'],
            default => [],
        };
    }

    private function resourceTypeNames(): array
    {
        return $this->resourceTypeNames ??= [
            'encounter' => dictionary()
                ->basics()
                ->byName('eHealth/encounter_types', false)
                ->asCodeDescription()
                ->all(),

            'specimen' => dictionary()
                ->basics()
                ->byName('specimen_types', false)
                ->asCodeDescription()
                ->all(),
        ];
    }

    public function resourceName(Model $record, string $type): ?string
    {
        return match ($type) {
            'episode_of_care' => $record->name,
            'diagnostic_report', 'procedure' => $record->code?->displayValue ?: $record->code?->value,
            'encounter', 'specimen' => $this->resourceTypeNames()[$type][$record->type?->coding?->first()?->code]
                ?? $record->type?->text
                ?? $record->type?->coding?->first()?->code,
            CarePlan::RESOURCE_TYPE => $record->title,
            'service_request' => $record->requestNumber,
            'condition', 'observation', 'clinical_impression' => $record->code?->text
                ?: $record->code?->coding?->first()?->code,
            'activity' => $record->description,
            'immunization' => $record->vaccineCode?->text
                ?: $record->vaccineCode?->coding?->first()?->code,
            'device' => $record->type?->text
                ?: $record->type?->coding?->first()?->code,
            default => null,
        };
    }

    public function resourceDate(Model $record, string $type): string
    {
        return match ($type) {
            'episode_of_care', 'encounter' => collect([
                $record->period?->start,
                $record->period?->end,
            ])->filter()->map(fn ($date) => formatDisplayDate($date))->implode(' - '),

            'diagnostic_report' => $record->effectiveDateTime
                ? formatDisplayDate($record->effectiveDateTime)
                : (collect([
                    $record->effectivePeriod?->start,
                    $record->effectivePeriod?->end,
                ])->filter()->map(fn ($date) => formatDisplayDate($date))->implode(' - ')
                    ?: formatDisplayDate($record->issued)),

            'procedure' => $record->performedDateTime
                ? formatDisplayDate($record->performedDateTime)
                : collect([
                    $record->performedPeriod?->start,
                    $record->performedPeriod?->end,
                ])->filter()->map(fn ($date) => formatDisplayDate($date))->implode(' - '),

            CarePlan::RESOURCE_TYPE => collect([
                $record->periodStart,
                $record->periodEnd,
            ])->filter()->map(fn ($date) => formatDisplayDate($date))->implode(' - '),

            'specimen' => formatDisplayDate(
                $record->collection?->collectedDateTime
                ?? $record->collection?->collectedPeriod?->start
                ?? $record->receivedTime
                ?? $record->ehealthInsertedAt
            ),

            'service_request' => formatDisplayDate($record->startedAt),
            'condition' => formatDisplayDate($record->assertedDate ?? $record->onsetDate),
            'observation' => formatDisplayDate($record->effectiveDateTime ?? $record->issued),
            'activity' => formatDisplayDate($record->scheduledPeriodStart),
            'clinical_impression' => formatDisplayDate($record->effectiveDateTime),
            'immunization' => formatDisplayDate($record->date),
            'device' => formatDisplayDate($record->ehealthInsertedAt),
            default => '',
        };
    }

    public function recordLabel(Model $record, string $type): string
    {
        $name = $this->resourceName($record, $type);
        $date = $this->resourceDate($record, $type);

        return ($name ?: __('approval.resource_types.'.$type)).($date !== '' ? ', '.$date : '');
    }

    public static function approvalStatusAllowed(string $type, ?string $status): bool
    {
        return !isset(self::ALLOWED_STATUSES[$type]) || in_array($status, self::ALLOWED_STATUSES[$type], true);
    }

    /**
     * Returns patient referrals from eHealth, indexed by their UUID, with availability for approval.
     *
     * @return array<string, array<string, mixed>>
     */
    public function patientReferrals(Person $person): array
    {
        $referrals = [];
        $page = 1;

        do {
            $response = EHealth::serviceRequest()->getBySearchParams(
                $person->uuid,
                ['page' => $page]
            );

            foreach ($response->validate() as $referral) {
                $uuid = $referral['id'];

                $referrals[$uuid] = [
                    'id' => $uuid,
                    'requisition' => $referral['requisition'] ?? '',
                    'status' => $referral['status'],
                    'authored_on' => $referral['authored_on'] ?? null,
                    'program_processing_status' => $referral['program_processing_status'] ?? null,
                    'category' => data_get($referral, 'category.coding.0.code'),
                    'service_id' => data_get($referral, 'code.identifier.value'),
                    'selectable' => $referral['status'] === 'active' && !empty($referral['permitted_resources']),
                ];
            }

            $page++;
        } while ($response->isNotLast());

        return $referrals;
    }

    /** @return array<int, array<string, mixed>> */
    public function availableAuthenticationMethods(Person $person): array
    {
        return $person->authenticationMethods()
            ->where(function ($query): void {
                $query->whereNull('ehealth_ended_at')
                    ->orWhere('ehealth_ended_at', '>', now());
            })
            ->whereIn('type', [
                AuthenticationMethod::OTP->value,
                AuthenticationMethod::OFFLINE->value,
                AuthenticationMethod::THIRD_PERSON->value,
            ])
            ->whereNotNull('uuid')
            ->get()
            ->map(static fn (AuthenticationMethodModel $method): array => [
                ...$method->toArray(),
                'phoneNumber' => $method->maskedPhoneNumber
            ])
            ->all();
    }

    /**
     * Enrich eHealth authentication methods with normalized identifiers and local confidant details when available.
     *
     * @param  array<int, array<string, mixed>>  $methods
     * @return array<int, array<string, mixed>>
     */
    public function enrichAuthenticationMethods(array $methods, ?Person $person = null): array
    {
        $confidantsByUuid = collect();

        if ($person !== null && collect($methods)->contains(static fn (mixed $m): bool => is_array($m) && ($m['type'] ?? null) === AuthenticationMethod::THIRD_PERSON->value)) {
            $person->loadMissing([
                'confidantPersons.person.names',
                'confidantPersons.person.documents',
                'confidantPersons.person.phones',
            ]);

            $confidantsByUuid = $person->confidantPersons
                ->filter(static fn ($relation): bool => $relation->person !== null && filled($relation->person->uuid))
                ->keyBy(static fn ($relation): string => (string) $relation->person->uuid);
        }

        return collect($methods)
            ->filter(static function (mixed $method): bool {
                if (!is_array($method)) {
                    return false;
                }

                if (!in_array($method['type'] ?? null, [
                    AuthenticationMethod::OTP->value,
                    AuthenticationMethod::OFFLINE->value,
                    AuthenticationMethod::THIRD_PERSON->value,
                ], true)) {
                    return false;
                }

                $endedAt = $method['ehealth_ended_at'] ?? ($method['ehealthEndedAt'] ?? ($method['ended_at'] ?? ($method['endedAt'] ?? null)));

                return empty($endedAt) || CarbonImmutable::parse((string) $endedAt)->isFuture();
            })
            ->map(static function (array $method) use ($confidantsByUuid): array {
                $uuid = $method['id'] ?? ($method['uuid'] ?? null);
                if ($uuid !== null) {
                    $method['id'] ??= $uuid;
                    $method['uuid'] ??= $uuid;
                }

                if (($method['type'] ?? null) === AuthenticationMethod::THIRD_PERSON->value
                    && empty($method['confidantPerson'])
                    && empty($method['confidant_person'])
                    && !empty($method['value'])
                ) {
                    $relation = $confidantsByUuid->get((string) $method['value']);
                    $confidant = $relation?->person;

                    if ($confidant !== null) {
                        $firstPhone = $confidant->phones?->first();
                        $method['confidantPerson'] = [
                            'name' => $confidant->fullName,
                            'taxId' => $confidant->taxId,
                            'unzr' => $confidant->unzr,
                            'documentsPerson' => $confidant->documents?->toArray() ?? [],
                            'phones' => $firstPhone ? ['number' => $firstPhone->number, 'type' => $firstPhone->type] : null,
                        ];
                    }
                }

                return $method;
            })
            ->values()
            ->all();
    }

    public function resolveConfirmationMethod(array $method, ?string $patientUuid, ?string $authorizeWith): array
    {
        if (AuthenticationMethod::confirmationMethod($method) !== null) {
            return $method;
        }
        if (($method['type'] ?? null) === AuthenticationMethod::THIRD_PERSON->value && !empty($method['number'])) {
            $method['reference'] = ['type' => AuthenticationMethod::OTP->value, 'phone_number' => $method['number']];

            return $method;
        }
        if ($patientUuid === null || $authorizeWith === null) {
            return $method;
        }
        $methods = EHealth::person()->getAuthMethods($patientUuid)->validate();
        $selected = collect($methods)->first(fn (array $candidate): bool => ($candidate['id'] ?? ($candidate['uuid'] ?? null)) === $authorizeWith);
        if (!is_array($selected)) {
            return $method;
        }
        if (in_array($selected['type'] ?? null, [AuthenticationMethod::OTP->value, AuthenticationMethod::OFFLINE->value], true)) {
            return array_replace($method, $selected, [
                'phone_number' => $selected['phone_number'] ?? ($selected['number'] ?? ($method['phone_number'] ?? null)),
            ]);
        }
        if (($selected['type'] ?? null) !== AuthenticationMethod::THIRD_PERSON->value) {
            return $method;
        }
        $type = AuthenticationMethod::confirmationMethod($selected);
        if ($type === null && !empty($selected['value'])) {
            $representativeMethods = collect($this->enrichAuthenticationMethods(
                EHealth::person()->getAuthMethods($selected['value'])->validate()
            ));
            $reference = $representativeMethods->firstWhere('type', AuthenticationMethod::OTP->value)
                ?? $representativeMethods->firstWhere('type', AuthenticationMethod::OFFLINE->value);
            $type = AuthenticationMethod::confirmationMethod($reference ?? []);
            $selected = $reference ?? [];
        }
        if ($type === null) {
            return $method;
        }
        $method['type'] = AuthenticationMethod::THIRD_PERSON->value;
        $method['reference'] = [
            'type' => $type->value,
            'phone_number' => $method['phone_number'] ?? ($method['number'] ?? AuthenticationMethod::confirmationPhone($selected)),
        ];

        return $method;
    }

    /**
     * Returns referral details from eHealth, including its permitted resources.
     *
     * @return array<string, mixed>
     */
    public function referralDetails(Person $person, string $uuid): array
    {
        return EHealth::serviceRequest()
            ->getById($person->uuid, $uuid)
            ->validate();
    }

    /** @return array{approval_id: ?string, link_id: ?int} */
    public function create(Person $person, array $payload): array
    {
        $user = Auth::user();

        try {
            $response = EHealth::approval()->createApproval($person->uuid, $payload);

            if (!$response->successful()) {
                throw new EHealthResponseException($response);
            }
        } catch (EHealthConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            throw $exception;
        }

        $data = $response->validate();

        if ($response->getStatusCode() !== 202) {
            Repository::approval()->sync($data, $person);

            return [
                'approval_id' => $data['id'],
                'link_id' => null,
            ];
        }

        $creator = $user->employees()
            ->with('party')
            ->where('uuid', $payload['created_by']['identifier']['value'])
            ->firstOrFail();

        $approval = Repository::approval()->storePending(
            $person,
            $payload,
            $creator
        );

        $link = Repository::approval()->attachEhealthLink($approval, ['href' => $data['links'][0]['href']]);
        Repository::approval()->recordPatientRequest($link, $payload + ['patientApprovalAction' => 'create']);
        $this->dispatchJob($link, $user, Session::get(config('ehealth.api.oauth.bearer_token')));

        return [
            'approval_id' => null,
            'link_id' => $link->id,
        ];
    }

    public function cancel(Approval $approval): void
    {
        $approvable = $approval->approvable;
        $patient = $approvable instanceof Person ? $approvable : $approvable->person;

        $this->cancelByUuid($patient, $approval->uuid);
    }

    public function cancelByUuid(Person $patient, string $approvalUuid): void
    {
        $response = EHealth::approval()->cancelApproval($patient->uuid, $approvalUuid);
        $data = $response->validate();
        $status = $response->status();

        if ($status !== 202 && ($data['id'] ?? null) !== $approvalUuid) {
            throw new EHealthValidationException(['error' => ['message' => 'Cancellation result does not match the requested approval.']]);
        }

        if ($status === 202) {
            if (empty($data['links'][0]['href'])) {
                throw new EHealthValidationException([
                    'error' => ['message' => 'Missing cancellation job link.'],
                ]);
            }

            app(EHealthJobResolver::class)->resolve($data);
        }

        Repository::approval()->markCancelled($approvalUuid);
    }

    private function dispatchJob(EhealthLink $link, User $user, string $token): void
    {
        $legalEntity = legalEntity();

        Bus::batch([
            new RemoteEHealthLinksProcessing(
                eHealthLink: $link,
                legalEntity: $legalEntity,
                standalone: true
            ),
        ])
            ->withOption('legal_entity_id', $legalEntity->id)
            ->withOption('token', Crypt::encryptString($token))
            ->withOption('user', $user)
            ->onQueue('sync')
            ->name(RemoteEHealthLinksProcessing::BATCH_NAME)
            ->dispatch();
    }

    /** @return array{status: string, approval_id?: string, message?: string} */
    public function jobStatus(int $linkId, Person $person): array
    {
        $link = EhealthLink::with(['job', 'linkable'])
            ->findOrFail($linkId);

        if ($link->linkable?->approvable_type !== Person::class || $link->linkable?->approvable_id !== $person->id) {
            throw new RuntimeException('Approval does not belong to this patient.');
        }

        $status = strtoupper((string) $link->job?->status);

        if ($status === JobStatus::FAILED->value) {
            return [
                'status' => 'failed',
                'message' => __('approval.create.rejected'),
            ];
        }

        if ($status !== JobStatus::PROCESSED->value) {
            return ['status' => 'pending'];
        }

        $approvalId = $link->linkable?->fresh()?->uuid;

        if (!$approvalId) {
            throw new RuntimeException('Processed approval is missing its ID.');
        }

        return [
            'status' => 'ready',
            'approval_id' => $approvalId,
        ];
    }

    public function details(Person $person, string $approvalId): array
    {
        try {
            $response = EHealth::approval()->getApprovalDetails($person->uuid, $approvalId);

            if (!$response->successful()) {
                throw new EHealthResponseException($response);
            }

            $data = $response->validate();
            if (($data['id'] ?? null) !== $approvalId) {
                throw new EHealthValidationException(['error' => ['message' => 'Approval details do not match the requested approval.']]);
            }

            return $data;
        } catch (EHealthConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            throw $exception;
        }
    }

    /**
     * Returns a job link ID if verification is asynchronous.
     * Returns null when the approval is already verified.
     */
    public function verify(Person $person, string $approvalId, ?int $code): ?int
    {
        try {
            $response = EHealth::approval()->verify(
                $person->uuid,
                $approvalId,
                $code === null ? [] : ['code' => $code]
            );

            if (!$response->successful()) {
                throw new EHealthResponseException($response);
            }
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() !== 409) {
                throw $exception;
            }

            $data = $this->details($person, $approvalId);

            if (($data['is_verified'] ?? false) !== true
                || (isset($data['status']) && ApprovalStatus::resolve($data['status'])?->isGranted() !== true)
                || (isset($data['expires_at']) && !CarbonImmutable::parse($data['expires_at'])->isFuture())) {
                throw $exception;
            }

            Repository::approval()->sync($data, $person);

            return null;
        }

        $data = $response->validate();

        if ($response->getStatusCode() === 202) {
            // An accepted verification job is pending, not evidence of patient consent.
            $approval = Approval::query()
                ->where('approvable_type', Person::class)
                ->where('approvable_id', $person->id)
                ->where('uuid', $approvalId)
                ->firstOrFail();

            $link = Repository::approval()->attachEhealthLink($approval, ['href' => $data['links'][0]['href']]);
            Repository::approval()->recordPatientRequest($link, ['patientApprovalAction' => 'verify']);

            $this->dispatchJob(
                $link,
                Auth::user(),
                Session::get(config('ehealth.api.oauth.bearer_token'))
            );

            return $link->id;
        }

        $this->syncVerified($person, $approvalId);

        return null;
    }

    public function syncVerified(Person $person, string $approvalId): void
    {
        $data = $this->details($person, $approvalId);

        if (($data['is_verified'] ?? false) !== true
            || (isset($data['status']) && ApprovalStatus::resolve($data['status'])?->isGranted() !== true)
            || (isset($data['expires_at']) && !CarbonImmutable::parse($data['expires_at'])->isFuture())) {
            throw new RuntimeException('eHealth has not confirmed an active approval.');
        }

        Repository::approval()->sync($data, $person);
    }

    public function smsResendAvailableAt(Person $person, string $approvalId): int
    {
        $approval = Approval::query()
            ->where('approvable_type', Person::class)
            ->where('approvable_id', $person->id)
            ->where('uuid', $approvalId)
            ->firstOrFail();

        return $this->smsResendTimestamp($approval);
    }

    private function smsResendTimestamp(Approval $approval): int
    {
        $timestamp = $approval->updated_at ?? $approval->created_at;

        return $timestamp->copy()->addMinute()->timestamp;
    }

    public function resendSms(Person $person, string $approvalId): void
    {
        $approval = Approval::query()
            ->where('approvable_type', Person::class)
            ->where('approvable_id', $person->id)
            ->where('uuid', $approvalId)
            ->firstOrFail();

        if ($approval->getAttribute('is_verified')) {
            throw ValidationException::withMessages([
                'smsResend' => __('approval.create.resend_verified'),
            ]);
        }

        if (now()->timestamp < $this->smsResendTimestamp($approval)) {
            throw ValidationException::withMessages([
                'smsResend' => __('approval.create.resend_too_early'),
            ]);
        }

        $response = EHealth::approval()->resendSms($person->uuid, $approvalId);

        if (!$response->successful()) {
            throw new EHealthResponseException($response);
        }
    }
}
