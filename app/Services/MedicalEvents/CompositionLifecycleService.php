<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Enums\Person\CompositionStatus;
use App\Enums\Person\CompositionType;
use App\Exceptions\EHealth\EHealthException;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Drives a medical conclusion through create, poll, read and sign.
 *
 * A conclusion is never mirrored locally before eHealth has assigned it an id. The local
 * table is a projection of what exists remotely, so writing a placeholder row at submit
 * time and hoping to correct it later leaves the two permanently out of step whenever the
 * async job fails or the browser goes away mid-flight.
 */
class CompositionLifecycleService
{
    public const string JOB_PENDING = 'PENDING';

    public const string JOB_DONE = 'DONE';

    public const string JOB_FAILED = 'FAILED';

    /**
     * Submit a conclusion and return the async job it scheduled.
     *
     * createComposition carries the whole conclusion as a detached signature, so what is
     * sent is the KEP-signed payload rather than the mapper array itself. Taking the
     * signed string as the parameter keeps it impossible to call this with an unsigned
     * body by mistake.
     *
     * @param  string  $signedContent  Base64-encoded PKCS#7 signed createComposition body.
     * @return array{id: string|null, eta: string|null, status: string|null}
     */
    public function create(string $signedContent): array
    {
        return $this->jobFrom(EHealth::composition()->create(['data' => $signedContent])->getData());
    }

    /**
     * Mark a conclusion as entered in error and return the async job it scheduled.
     *
     * @param  string  $signedContent  Base64-encoded PKCS#7 signed cancellation body.
     * @return array{id: string|null, eta: string|null, status: string|null}
     */
    public function cancel(string $compositionUuid, string $signedContent): array
    {
        return $this->jobFrom(
            EHealth::composition()->cancel($compositionUuid, ['data' => $signedContent])->getData()
        );
    }

    /**
     * Retry ERLN registration and return the async job it scheduled.
     *
     * @return array{id: string|null, eta: string|null, status: string|null}
     */
    public function resendErln(string $compositionUuid): array
    {
        return $this->jobFrom(EHealth::composition()->resendErln($compositionUuid)->getData());
    }

    /**
     * Read the current state of an async job.
     *
     * @return array{status: string, compositionUuid: string|null, errors: list<string>}
     */
    public function jobStatus(string $jobId): array
    {
        $data = EHealth::composition()->getAsyncJobStatus($jobId)->validate();

        return [
            'status' => (string) (data_get($data, 'status') ?? self::JOB_PENDING),
            'compositionUuid' => $this->compositionUuidFromLinks(data_get($data, 'links', [])),
            'errors' => $this->errorsFrom($data),
        ];
    }

    /**
     * Resolve which conclusion a finished job produced.
     *
     * The contract does not commit to how the id is exposed: the documented payload shows
     * `links` carrying only an `entity`, while the sequence diagram refers to an `href` on
     * the same item. So the links are searched for a usable id first, and if that yields
     * nothing the conclusion is located by the encounter it was built on — which is unique
     * per signed conclusion and therefore unambiguous for the one just created.
     */
    public function resolveCreatedComposition(
        array $jobLinks,
        string $patientUuid,
        string $encounterUuid,
        CompositionType $type
    ): ?string {
        $fromLinks = $this->compositionUuidFromLinks($jobLinks);

        if ($fromLinks !== null) {
            return $fromLinks;
        }

        try {
            $results = EHealth::composition()->search([
                'subject' => $patientUuid,
                'encounter' => $encounterUuid,
                'type' => $type->value,
            ])->validate();
        } catch (\Throwable $exception) {
            Log::warning('Could not locate the created composition by encounter', [
                'encounter' => $encounterUuid,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        return collect($results)
            ->sortByDesc(static fn (array $item) => data_get($item, 'date'))
            ->pluck('identifier.value')
            ->filter()
            ->first();
    }

    /**
     * Fetch the conclusion exactly as eHealth stored it.
     *
     * Both TV 3.8.1.7 and 3.8.2.9 require the object to be read back before signing, so
     * that what the author signs is the system's own record rather than the form input.
     *
     * @return array<string, mixed>
     */
    public function fetchDetails(
        string $patientUuid,
        string $compositionUuid,
        string $episodeUuid,
        string $encounterUuid
    ): array {
        return EHealth::composition()
            ->getById($patientUuid, $compositionUuid, $episodeUuid, $encounterUuid)
            ->validate();
    }

    /**
     * Re-read a locally projected conclusion straight from eHealth.
     *
     * Chain rules (TV 3.8.2.12, 3.8.2.13) have to be decided on the conclusion as it
     * exists now, not on the copy this MIS happened to cache: a conclusion may have been
     * cancelled or superseded elsewhere since the row was written.
     *
     * @return array<string, mixed> Empty when the read context is incomplete.
     */
    public function fetchDetailsFor(Composition $composition): array
    {
        if (!$composition->hasReadContext) {
            return [];
        }

        return $this->fetchDetails(
            (string) $composition->patientUuid,
            $composition->uuid,
            (string) $composition->episodeOfCareUuid,
            (string) $composition->encounterUuid
        );
    }

    /**
     * Whether eHealth already holds a birth conclusion for this newborn (TV 3.8.1.3).
     *
     * A newborn may carry only one conclusion that is not in error, so the check has to
     * reach eHealth: a conclusion issued by another MIS never appears in the local
     * projection, and relying on that projection alone would permit a duplicate.
     */
    public function hasActiveNewbornConclusion(string $prepersonUuid): bool
    {
        $response = EHealth::composition()->search([
            'subject' => $prepersonUuid,
            'type' => CompositionType::NEWBORN->value,
        ]);

        $results = $response->getData();

        if (empty($results)) {
            $results = $response->json() ?? [];
        }

        return collect($results)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->contains(static fn (array $item): bool => CompositionStatus::fromEHealth(data_get($item, 'status'))
                !== CompositionStatus::ENTERED_IN_ERROR);
    }

    /**
     * Submit the author's signature over the conclusion.
     *
     * @return array{id: string|null, eta: string|null, status: string|null}
     */
    public function sign(string $compositionUuid, string $signedContent): array
    {
        return $this->jobFrom(
            EHealth::composition()->sign($compositionUuid, ['data' => $signedContent])->getData()
        );
    }

    /**
     * Normalise the async job every write endpoint answers with.
     *
     * @return array{id: string|null, eta: string|null, status: string|null}
     */
    private function jobFrom(mixed $data): array
    {
        return [
            // The response reader renames eHealth's `id` to `uuid`, so accept either.
            'id' => data_get($data, 'id') ?? data_get($data, 'uuid'),
            'eta' => data_get($data, 'eta'),
            'status' => data_get($data, 'status'),
        ];
    }

    /**
     * Mirror a conclusion returned by getComposition into the local table.
     *
     * Only the fields the response actually carries are written, so a later, narrower
     * refresh cannot blank out details captured here.
     */
    public function storeLocal(
        array $details,
        Person|Preperson $patient,
        ?string $episodeUuid = null,
        ?string $asyncJobId = null
    ): ?Composition {
        $uuid = data_get($details, 'identifier.value');

        if (!$uuid) {
            return null;
        }

        $isPreperson = $patient instanceof Preperson;

        $attributes = array_filter([
            'person_id' => $isPreperson ? null : $patient->id,
            'preperson_id' => $isPreperson ? $patient->id : null,
            'status' => CompositionStatus::fromEHealth(data_get($details, 'status'))?->value,
            'title' => data_get($details, 'title'),
            'date' => data_get($details, 'date'),
            'type_id' => $this->storeCodeableConcept(data_get($details, 'type')),
            'category_id' => $this->storeCodeableConcept(data_get($details, 'category')),
            'encounter_id' => $this->storeIdentifier(data_get($details, 'encounter')),
            'author_id' => $this->storeIdentifier(data_get($details, 'author')),
            'custodian_id' => $this->storeIdentifier(data_get($details, 'custodian')),
            'subject_id' => $this->storeIdentifier(data_get($details, 'subject')),
            'section_focus_id' => $this->storeIdentifier(data_get($details, 'section.focus')),
            'episode_of_care_id' => $this->storeIdentifier(
                $episodeUuid ? ['value' => $episodeUuid, 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'episode']]]] : null
            ),
            'relates_to_code' => data_get($details, 'relatesTo.code'),
            'relates_to_target_id' => $this->storeIdentifier(data_get($details, 'relatesTo.targetIdentifier')),
            'extension' => data_get($details, 'extension'),
            'async_job_id' => $asyncJobId,
            'data' => $details,
        ], static fn (mixed $value) => $value !== null);

        $composition = Composition::updateOrCreate(['uuid' => $uuid], $attributes);

        $period = data_get($details, 'event.0.period');
        if (is_array($period)) {
            Repository::period()->sync($composition, [
                'start' => $period['start'] ?? null,
                'end' => $period['end'] ?? null,
            ], 'eventPeriod');
        }

        return $composition->refresh();
    }

    /**
     * Read the third-party processing state and mirror the pieces the registry shows.
     *
     * ERLN (мВТН) and DRACS/DIIA (МВН) share the same endpoint. Only the ERLN create
     * task is denormalised onto columns, because that is what the resend action and
     * the list badge key off; everything else stays in `data._integration`.
     *
     * @return list<array<string, mixed>>
     */
    public function syncIntegration(Composition $composition): array
    {
        if (!$composition->hasReadContext) {
            return [];
        }

        $items = $this->fetchIntegration($composition);

        $erln = collect($items)->first(
            static fn (mixed $item): bool => is_array($item)
                && data_get($item, 'component') === 'ERLN'
                && data_get($item, 'type') === 'CREATE_ERLN_RECORD'
        );

        $stored = $composition->data ?? [];
        $stored['_integration'] = $items;

        $composition->update(array_filter([
            'data' => $stored,
            'erln_status' => is_array($erln) ? data_get($erln, 'integrationStatus') : null,
            'erln_record_number' => is_array($erln) ? data_get($erln, 'details.SL_NUM') : null,
            'erln_status_message' => is_array($erln) ? data_get($erln, 'statusMessage') : null,
        ], static fn (mixed $value) => $value !== null));

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchIntegration(Composition $composition): array
    {
        if (!$composition->hasReadContext) {
            return [];
        }

        $response = EHealth::composition()->getIntegrationData(
            $composition->patientUuid,
            $composition->uuid,
            $composition->episodeOfCareUuid,
            $composition->encounterUuid
        );

        $data = $response->getData();
        if (empty($data)) {
            $data = $response->json() ?? [];
        }

        return array_values(array_filter(
            Arr::wrap($data),
            static fn (mixed $item): bool => is_array($item)
        ));
    }

    /**
     * TV 3.8.1.10.1 — a birth conclusion may not be cancelled once any integration
     * process has started (DRACS / DIIA).
     */
    public function hasIntegrationProcesses(Composition $composition): bool
    {
        return $this->fetchIntegration($composition) !== [];
    }

    private function storeCodeableConcept(mixed $concept): ?int
    {
        if (!is_array($concept) || empty($concept['coding'][0]['code'])) {
            return null;
        }

        // Search payloads sometimes omit `system`; CodeableConceptRepository requires the key.
        $concept['coding'][0]['system'] ??= match ((string) $concept['coding'][0]['code']) {
            CompositionType::NEWBORN->value, CompositionType::TEMP_DISABILITY->value => 'COMPOSITION_TYPES',
            default => 'COMPOSITION_CATEGORIES',
        };

        return Repository::codeableConcept()->store($concept)->id;
    }

    private function storeIdentifier(mixed $reference): ?int
    {
        $value = data_get($reference, 'value');

        if (!is_string($value) || $value === '') {
            return null;
        }

        $identifier = Repository::identifier()->store($value);
        $type = data_get($reference, 'type');

        if (is_array($type)) {
            Repository::codeableConcept()->attach($identifier, [
                'identifier' => ['type' => $type],
            ]);
        }

        return $identifier->id;
    }

    /**
     * Pull a conclusion id out of the job's links, tolerating the shapes the contract
     * leaves open.
     */
    private function compositionUuidFromLinks(mixed $links): ?string
    {
        foreach (Arr::wrap($links) as $link) {
            foreach (Arr::wrap($link) as $value) {
                if (!is_string($value)) {
                    continue;
                }

                if (preg_match('~(?:/|^)composition/([0-9a-f-]{36})~i', $value, $matches) === 1) {
                    return $matches[1];
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function errorsFrom(mixed $data): array
    {
        $linkErrors = collect(data_get($data, 'links', []))
            ->pluck('error')
            ->filter()
            ->all();

        $errors = data_get($data, 'error') ?? data_get($data, 'errors') ?? [];

        if (is_string($errors)) {
            $errors = [$errors];
        }

        $allErrors = array_merge(
            $linkErrors,
            collect(Arr::wrap($errors))
                ->map(static function (mixed $error): string {
                    if (!is_array($error)) {
                        return (string) $error;
                    }

                    $message = (string) (data_get($error, 'message')
                        ?? data_get($error, 'rules.0.description')
                        ?? '');

                    if ($message === '') {
                        return (string) json_encode($error);
                    }

                    $code = data_get($error, 'code') ?? data_get($error, 'rules.0.code');

                    // eHealth often returns "1172: …" already; otherwise join code + text.
                    if ($code !== null && $code !== '' && !preg_match('/^\d{3,5}:/u', $message)) {
                        return trim((string) $code).': '.$message;
                    }

                    return $message;
                })
                ->filter()
                ->values()
                ->all()
        );

        return array_values(array_unique(array_map(
            static fn (string $error): string => EHealthException::translate($error),
            $allErrors
        )));
    }
}
