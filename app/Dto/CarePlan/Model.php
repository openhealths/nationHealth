<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Classes\eHealth\Api\Responses\Collections\CarePlanDetails;
use App\Dto\CodeableConcept\Model as CodeableConceptData;
use App\Dto\Identifier\Model as IdentifierData;
use App\Dto\Input\FormCodeableConcept;
use App\Dto\Input\FormIdentifier;
use App\Enums\CarePlanStatus;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Models\CarePlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Condition\TargetClass;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

/**
 * Map the validated form to the attributes and relations of a local CarePlan draft.
 *
 * Persisted attributes need explicit target mappings because Eloquent exposes them
 * through attribute access instead of declared properties. The remaining values
 * are used to build the related concepts, identifiers and period.
 */
#[Map(source: CarePlanForm::class)]
#[Map(source: CarePlanDetails::class)]
#[Map(target: CarePlan::class)]
class Model
{
    #[Map(source: '[id]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $uuid;

    #[Map(if: new TargetClass(CarePlan::class))]
    public int $personId;

    #[Map(if: new TargetClass(CarePlan::class))]
    public int $legalEntityId;

    #[Map(if: new TargetClass(CarePlan::class))]
    public ?int $authorId = null;

    public string $authorUuid;

    #[Map(if: new TargetClass(CarePlan::class))]
    public ?int $encounterId = null;

    #[Map(source: '[status]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $status = CarePlanStatus::DRAFT->value;

    #[Map(source: '[intent]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(source: 'intent', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $intent = 'order';

    #[Map(source: '[title]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(source: 'title', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $title = '';

    #[Map(source: '[requisition]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $requisition = null;

    public string $category = '';

    public string $termsOfService = '';

    #[Map(source: '[context]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(source: 'context', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $context = null;

    #[Map(source: '[description]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(source: 'description', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $description = null;

    #[Map(source: '[note]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(source: 'note', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $note = null;

    #[Map(source: '[inform_with?][auth_method_id?]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(source: 'informWith', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $informWith = null;

    public string $periodStart = '';

    public string $periodStartTime = '';

    public string $periodEnd = '';

    public string $periodEndTime = '';
    public string $encounter = '';

    /** @var list<array<string, mixed>> */
    #[Map(if: new TargetClass(CarePlan::class))]
    public array $addresses = [];

    public CodeableConceptData $categoryConcept;
    public CodeableConceptData $termsOfServiceConcept;
    public IdentifierData $authorReference;
    public IdentifierData $subjectReference;
    public IdentifierData $encounterIdentifier;
    public IdentifierData $managingOrganization;

    /** @var array{start: string, end: string|null} */
    public array $period;

    /** @var Collection<int, IdentifierData> */
    public Collection $supportingInfoReferences;

    /** @var array<string, string> */
    public array $medicalRecordLabels;

    /** @var Collection<int, IdentifierData> */
    public Collection $contributors;

    /**
     * Map validated local selections into the relations stored with the draft.
     *
     * @param list<string> $coAuthors
     * @param list<string> $episodeUuids
     * @param array<string, string> $medicalRecordLabels
     */
    public function prepareLocal(
        ObjectMapperInterface $mapper,
        string $patientUuid,
        string $legalEntityUuid,
        array $coAuthors,
        array $episodeUuids,
        array $medicalRecordLabels,
    ): void {
        $this->categoryConcept = $this->concept($mapper, 'eHealth/care_plan_categories', $this->category);
        $this->termsOfServiceConcept = $this->concept($mapper, 'PROVIDING_CONDITION', $this->termsOfService);
        $this->authorReference = $this->identifier($mapper, $this->authorUuid, 'employee');
        $this->subjectReference = $this->identifier($mapper, $patientUuid, 'patient');
        $this->encounterIdentifier = $this->identifier($mapper, $this->encounter, 'encounter');
        $this->managingOrganization = $this->identifier($mapper, $legalEntityUuid, 'legal_entity');
        $this->period = [
            'start' => $this->localDateTimeToUtc($this->periodStart, $this->periodStartTime),
            'end' => $this->periodEnd === '' ? null : $this->localDateTimeToUtc($this->periodEnd, $this->periodEndTime ?: '23:59:59'),
        ];
        $this->medicalRecordLabels = $medicalRecordLabels;
        $this->contributors = collect($coAuthors)->map(
            fn (string $uuid): IdentifierData => $this->identifier($mapper, $uuid, 'employee')
        );
        $this->supportingInfoReferences = collect($episodeUuids)->map(
            fn (string $uuid): IdentifierData => $this->identifier($mapper, $uuid, 'episode_of_care')
        )->concat(collect(array_keys($medicalRecordLabels))->map(function (string $key) use ($mapper): IdentifierData {
            [$type, $uuid] = explode(':', $key, 2);

            return $this->identifier($mapper, $uuid, $type);
        }));
        foreach (['context', 'description', 'note', 'informWith'] as $field) {
            $this->{$field} = $this->{$field} === '' ? null : $this->{$field};
        }
    }

    /** @var array<string, CodeableConceptData|null> */
    public array $remoteConcepts;

    /** @var array<string, array|null> */
    public array $remoteReferences;

    /** @var array<string, list<array>> */
    public array $remoteReferenceLists;

    /** Prepare the validated relations for normalized persistence. */
    public function prepareResponse(CarePlanDetails $details): void
    {
        $this->status = $details->status()->value;
        $this->remoteConcepts = [
            'categoryConcept' => CodeableConceptData::map($details->get('category')),
            'termsOfServiceConcept' => CodeableConceptData::map($details->get('terms_of_service')),
            'statusReasonConcept' => CodeableConceptData::map($details->statusReason()),
        ];
        $this->prepareResourceRelations($details->toResource());
    }

    protected function prepareResourceRelations(array $resource): void
    {
        $this->remoteReferences = [
            'subjectReference' => $resource['subject'] ?? null,
            'authorReference' => $resource['author'] ?? null,
            'encounterIdentifier' => $resource['encounter'] ?? null,
            'managingOrganization' => $resource['managingOrganization'] ?? null,
            'basedOn' => $resource['basedOn'] ?? null,
            'partOf' => $resource['partOf'] ?? null,
            'careManager' => $resource['careManager'] ?? null,
        ];
        $this->remoteReferenceLists = [
            'supportingInfoReferences' => $resource['supportingInfo'] ?? [],
            'contributors' => $resource['contributor'] ?? [],
        ];
        $this->period = $resource['period'];
        $this->addresses = $resource['addresses'];
    }

    protected function concept(ObjectMapperInterface $mapper, string $system, string $code): CodeableConceptData
    {
        return $mapper->map(new FormCodeableConcept([
            'coding' => [['system' => $system, 'code' => $code]],
            'text' => null,
        ]), CodeableConceptData::class);
    }

    protected function identifier(ObjectMapperInterface $mapper, string $uuid, string $type): IdentifierData
    {
        return $mapper->map(new FormIdentifier([
            'value' => $uuid,
            'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]], 'text' => null],
        ]), IdentifierData::class);
    }

    protected function localDateTimeToUtc(string $date, string $time): string
    {
        $time = $time === '' ? '00:00' : $time;
        $format = strlen($time) === 5 ? 'H:i' : 'H:i:s';

        return CarbonImmutable::createFromFormat(
            '!'.config('app.date_format').' '.$format, $date.' '.$time, config('app.timezone')
        )->utc()->toIso8601String();
    }
}
