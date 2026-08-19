<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Enums\Person\CompositionStatus;
use App\Enums\Person\CompositionType;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\Person\Person;
use App\Models\Preperson;
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
     * Submit a conclusion request payload and return the async job it scheduled.
     *
     * @param  array<string, mixed>  $payload
     * @return array{id: string|null, eta: string|null, status: string|null}
     */
    public function create(array $payload): array
    {
        $data = EHealth::composition()->create($payload)->getData();

        return [
            'id' => data_get($data, 'id'),
            'eta' => data_get($data, 'eta'),
            'status' => data_get($data, 'status'),
        ];
    }

    /**
     * Read the current state of an async job.
     *
     * @return array{status: string, compositionUuid: string|null, errors: list<string>}
     */
    public function jobStatus(string $jobId): array
    {
        $data = EHealth::composition()->getAsyncJobStatus($jobId)->getData();

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
            $response = EHealth::composition()->search([
                'subject' => $patientUuid,
                'encounter' => $encounterUuid,
                'type' => $type->value,
            ]);
            $results = $response->getData();
            if (empty($results)) {
                $results = $response->json() ?? [];
            }
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
        $response = EHealth::composition()
            ->getById($patientUuid, $compositionUuid, $episodeUuid, $encounterUuid);

        $data = $response->getData();

        if (empty($data)) {
            $data = $response->json() ?? [];
        }

        return $data;
    }

    /**
     * Submit the author's signature over the conclusion.
     *
     * @return array{id: string|null, eta: string|null, status: string|null}
     */
    public function sign(string $compositionUuid, string $signedContent): array
    {
        $data = EHealth::composition()->sign($compositionUuid, ['data' => $signedContent])->getData();

        return [
            'id' => data_get($data, 'id'),
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
            'type' => data_get($details, 'type.coding.0.code'),
            'category' => data_get($details, 'category.coding.0.code'),
            'status' => CompositionStatus::fromEHealth(data_get($details, 'status'))?->value,
            'title' => data_get($details, 'title'),
            'encounter_uuid' => data_get($details, 'encounter.value'),
            'episode_of_care_uuid' => $episodeUuid,
            'author_uuid' => data_get($details, 'author.value'),
            'custodian_uuid' => data_get($details, 'custodian.value'),
            'section_focus_uuid' => data_get($details, 'section.focus.value'),
            'subject_uuid' => data_get($details, 'subject.value'),
            'event_period_start' => data_get($details, 'event.0.period.start'),
            'event_period_end' => data_get($details, 'event.0.period.end'),
            'composition_date' => data_get($details, 'date'),
            'relates_to_code' => data_get($details, 'relatesTo.code'),
            'relates_to_target_uuid' => data_get($details, 'relatesTo.targetIdentifier.value'),
            'async_job_id' => $asyncJobId,
            'data' => $details,
        ], static fn (mixed $value) => $value !== null);

        return Composition::updateOrCreate(['uuid' => $uuid], array_merge(
            $attributes,
            $this->extensionColumns(data_get($details, 'extension', []))
        ));
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

    /**
     * Flatten the extension list into the columns that mirror it.
     *
     * @return array<string, mixed>
     */
    private function extensionColumns(array $extensions): array
    {
        $byCode = collect($extensions)
            ->filter(static fn ($extension) => is_array($extension) && isset($extension['valueCode']))
            ->mapWithKeys(static fn (array $extension) => [
                $extension['valueCode'] => $extension['valueUuid']
                    ?? $extension['valueDate']
                    ?? $extension['valueString']
                    ?? $extension['valueBoolean']
                    ?? null,
            ]);

        return array_filter([
            'inform_with_uuid' => $byCode->get('INFORM_WITH'),
            'is_accident' => $byCode->get('IS_ACCIDENT'),
            'is_intoxicated' => $byCode->get('IS_INTOXICATED'),
            'is_foreign_treatment' => $byCode->get('IS_FOREIGN_TREATMENT'),
            'is_force_renew' => $byCode->get('IS_FORCE_RENEW'),
            'treatment_violation' => $byCode->get('TREATMENT_VIOLATION'),
            'treatment_violation_date' => $byCode->get('TREATMENT_VIOLATION_DATE'),
            'newborn_birth_date' => $byCode->get('NEWBORN_BIRTH_DATE'),
            'newborn_sex' => $byCode->get('NEWBORN_SEX'),
        ], static fn (mixed $value) => $value !== null);
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
                ->map(static fn (mixed $error) => is_array($error)
                    ? (string) (data_get($error, 'message') ?? data_get($error, 'rules.0.description') ?? json_encode($error))
                    : (string) $error)
                ->filter()
                ->values()
                ->all()
        );

        return array_values(array_unique($allErrors));
    }
}
