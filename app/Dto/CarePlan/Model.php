<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Core\Arr;
use App\Dto\Fhir\CodeableConcept;
use App\Dto\Fhir\Reference;
use App\Dto\Fhir\ResourceData;
use App\Dto\FormCollection;
use App\Mapping\Conditions\CollectionHasPath;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

/** Canonical CarePlan projection. Native create/cancel envelopes remain on the API. */
#[Map(source: FormCollection::class)]
#[Map(source: CarePlanForm::class)]
final class Model implements Arrayable
{
    use ResourceData;
    #[Map(if: false)]
    public readonly string $id;

    #[Map(source: '[title?]', if: new CollectionHasPath('title'))]
    #[Map(source: 'title', if: new SourceClass(CarePlanForm::class))]
    public mixed $title;

    #[Map(source: '[description?]', if: new CollectionHasPath('description'))]
    #[Map(source: 'description', if: new SourceClass(CarePlanForm::class))]
    public mixed $description;

    #[Map(source: '[note?]', if: new CollectionHasPath('note'))]
    #[Map(source: 'note', if: new SourceClass(CarePlanForm::class))]
    public mixed $note;

    #[Map(source: '[intent?]', if: new CollectionHasPath('intent'))]
    #[Map(source: 'intent', if: new SourceClass(CarePlanForm::class))]
    public mixed $intent;

    #[Map(source: '[status?]', if: new CollectionHasPath('status'))]
    public mixed $status;

    #[Map(source: '[requisition?]', if: new CollectionHasPath('requisition'))]
    public mixed $requisition;

    #[Map(source: '[context?]', if: new CollectionHasPath('context'))]
    #[Map(source: 'context', if: new SourceClass(CarePlanForm::class))]
    public mixed $context;

    #[Map(source: '[statusReason?]', if: new CollectionHasPath('statusReason'))]
    public mixed $statusReason;

    #[Map(source: '[category?]', if: new CollectionHasPath('category'), transform: [self::class, 'mapCategory'])]
    #[Map(source: 'category', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'mapCategory'])]
    public ?CodeableConcept $category;

    #[Map(source: '[termsOfService?]', if: new CollectionHasPath('termsOfService'), transform: [self::class, 'mapTerms'])]
    #[Map(source: 'termsOfService', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'mapTerms'])]
    public ?CodeableConcept $termsOfService;

    #[Map(source: '[author?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'mapAuthor'])]
    #[Map(source: 'author', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'mapAuthor'])]
    public ?Reference $author;

    #[Map(source: '[encounter?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'mapEncounter'])]
    #[Map(source: 'encounter', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'mapEncounter'])]
    public ?Reference $encounter;

    #[Map(source: '[careManager?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'mapCareManager'])]
    public ?Reference $careManager;

    #[Map(source: '[managingOrganization?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'mapManagingOrganization'])]
    public ?Reference $managingOrganization;

    #[Map(source: '[addresses?]', if: new CollectionHasPath('addresses'), transform: [self::class, 'mapAddresses'])]
    public ?array $addresses;

    #[Map(source: '[supportingInfo?]', if: new CollectionHasPath(['supportingInfo', 'episodes', 'medicalRecords']), transform: [self::class, 'mapSupportingInfo'])]
    #[Map(source: 'medicalRecords', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'mapSupportingInfo'])]
    public ?array $supportingInfo;

    #[Map(source: '[contributor?]', if: new CollectionHasPath(['contributor', 'coAuthors']), transform: [self::class, 'mapContributors'])]
    #[Map(source: 'coAuthors', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'mapContributors'])]
    public ?array $contributor;

    #[Map(source: '[basedOn?]', if: new CollectionHasPath('basedOn'))]
    #[Map(source: 'based_on', if: new SourceClass(CarePlanForm::class))]
    public mixed $basedOn;

    #[Map(source: '[partOf?]', if: new CollectionHasPath('partOf'))]
    #[Map(source: 'part_of', if: new SourceClass(CarePlanForm::class))]
    public mixed $partOf;

    #[Map(source: '[period?]', if: new CollectionHasPath(['period', 'periodStart', 'periodEnd']), transform: [self::class, 'mapPeriod'])]
    #[Map(source: 'periodStart', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'mapPeriod'])]
    public mixed $period;

    #[Map(source: '[informWith?]', if: new CollectionHasPath('informWith'), transform: [self::class, 'mapInformWith'])]
    #[Map(source: 'informWith', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'mapInformWith'])]
    public mixed $informWith;
    public function __construct(
        #[Map(if: false)] private readonly array $options = [],
        #[Map(if: false)] private readonly array $input = [],
    ) {
        $this->original = $options['resource'] ?? [];
        $id = $input['uuid'] ?? $options['id'] ?? $this->original['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new InvalidArgumentException('Care Plan mapping requires an allocated resource UUID.');
        }
        $this->id = $id;
    }

    public static function fromSource(FormCollection $source, array $context = []): self
    {
        $data = Arr::toCamelCase($source->all());
        foreach (['addresses', 'supportingInfo', 'contributor', 'basedOn', 'partOf'] as $field) {
            if (!array_key_exists($field, $data) && !array_key_exists($field, $context['resource'] ?? []) && array_key_exists($field, $context)) {
                $data[$field] = $context[$field];
            }
        }

        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())->map(new FormCollection($data), new self($context, $data));
    }

    public static function fromForm(CarePlanForm $form, array $resolvedFields, array $context = []): self
    {
        // Repository has already resolved identity, author, encounter and the seed resource.
        $target = new self($context, Arr::toCamelCase($resolvedFields));
        if (array_key_exists('addresses', $resolvedFields)) {
            $target->addresses = self::mapAddresses($resolvedFields['addresses']);
        }

        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())->map($form, $target);
    }

    public static function mapCategory(mixed $value, FormCollection|CarePlanForm $source, self $target): ?CodeableConcept
    {
        if ($source instanceof CarePlanForm) {
            $value = $target->input['category'] ?? null;
            $source = new FormCollection($target->input);
        }

        return $target->mapConcept($value, 'category', 'eHealth/care_plan_categories');
    }

    public static function mapTerms(mixed $value, FormCollection|CarePlanForm $source, self $target): ?CodeableConcept
    {
        if ($source instanceof CarePlanForm) {
            $value = $target->input['termsOfService'] ?? null;
            $source = new FormCollection($target->input);
        }

        return $target->mapConcept($value, 'termsOfService', 'PROVIDING_CONDITION');
    }

    private function mapConcept(mixed $value, string $field, string $system): ?CodeableConcept
    {
        if (is_string($value) && $value === data_get($this->original, $field.'.coding.0.code')) {
            $value = $this->original[$field];
        }

        return CodeableConcept::fromValue($value, $system);
    }

    public static function mapAuthor(mixed $value, FormCollection|CarePlanForm $source, self $target): ?Reference
    {
        if ($source instanceof CarePlanForm) {
            $value = $target->input['author'] ?? null;
            $source = new FormCollection($target->input);
        }

        return $target->mapReference($value, 'author', 'employee');
    }

    public static function mapEncounter(mixed $value, FormCollection|CarePlanForm $source, self $target): ?Reference
    {
        if ($source instanceof CarePlanForm) {
            $value = $target->input['encounter'] ?? null;
            $source = new FormCollection($target->input);
        }

        return $target->mapReference($value, 'encounter', 'encounter');
    }

    public static function mapCareManager(mixed $value, FormCollection $source, self $target): ?Reference
    {
        return $target->mapReference($value, 'careManager', 'employee');
    }

    public static function mapManagingOrganization(mixed $value, FormCollection $source, self $target): ?Reference
    {
        return $target->mapReference($value, 'managingOrganization', 'legal_entity');
    }

    private function mapReference(mixed $value, string $field, string $type): ?Reference
    {
        $value ??= $this->options[$field.'Uuid'] ?? ($field === 'author' ? ($this->options['employeeUuid'] ?? null) : null);
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && $value === data_get($this->original, $field.'.identifier.value')) {
            $value = $this->original[$field];
        }

        return Reference::fromValue($value, $type);
    }

    public static function mapAddresses(mixed $value): ?array
    {
        return self::mapRows($value, CodeableConcept::class);
    }

    public static function mapContributors(mixed $value, FormCollection|CarePlanForm $source, self $target): ?array
    {
        if ($source instanceof CarePlanForm) {
            $value = $target->input['contributor'] ?? null;
            $source = new FormCollection($target->input);
        }
        if ($source->has('coAuthors')) {
            $existing = $value ?? $target->original['contributor'] ?? [];
            $value = array_map(static fn (mixed $author): array => Reference::value($author, 'employee', $existing), $source['coAuthors']);
        }

        return self::mapRows($value, Reference::class);
    }

    public static function mapSupportingInfo(mixed $value, FormCollection|CarePlanForm $source, self $target): ?array
    {
        if ($source instanceof CarePlanForm) {
            $value = $target->input['supportingInfo'] ?? null;
            $source = new FormCollection($target->input);
        }
        $info = $source->has('supportingInfo') ? $value : ($target->original['supportingInfo'] ?? []);
        if ($source->has('episodes')) {
            $others = array_values(array_filter($info ?? [], static fn (array $reference): bool => data_get($reference, 'identifier.type.coding.0.code') !== 'episode_of_care'));
            $episodes = array_map(static fn (mixed $episode): array => Reference::value(is_array($episode) && !isset($episode['identifier']) ? ($episode['uuid'] ?? $episode['id'] ?? '') : $episode, 'episode_of_care', $info ?? []), $source['episodes']);
            $info = [...$others, ...$episodes];
        }
        if ($source->has('medicalRecords')) {
            $episodes = array_values(array_filter($info ?? [], static fn (array $reference): bool => data_get($reference, 'identifier.type.coding.0.code') === 'episode_of_care'));
            $info = [...$episodes, ...$source['medicalRecords']];
        }

        return self::mapRows($info, Reference::class);
    }

    public static function mapPeriod(mixed $value, FormCollection|CarePlanForm $source, self $target): mixed
    {
        if ($source instanceof CarePlanForm) {
            $value = $target->input['period'] ?? null;
            $source = new FormCollection($target->input);
        }
        if ($source->has('period')) {
            return $value;
        }
        $period = $target->original['period'] ?? [];
        foreach (['start', 'end'] as $boundary) {
            $field = 'period'.ucfirst($boundary);
            if ($source->has($field)) {
                $period[$boundary] = $target->dateTime($source[$field], $source[$field.'Time'] ?? null, $period[$boundary] ?? null, $boundary === 'end');
            }
        }

        return $period;
    }

    private function dateTime(?string $date, ?string $time, ?string $original, bool $end): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        $timezone = $this->options['timezone'] ?? 'UTC';
        $format = $this->options['dateFormat'] ?? 'Y-m-d';
        $parsed = CarbonImmutable::hasFormat($date, $format) ? CarbonImmutable::createFromFormat('!'.$format, $date, $timezone) : CarbonImmutable::parse($date, $timezone);
        if ($original !== null) {
            $previous = CarbonImmutable::parse($original)->setTimezone($timezone);
            if ($previous->toDateString() === $parsed->toDateString() && ($time === null || $time === '' || $previous->format(strlen($time) === 5 ? 'H:i' : 'H:i:s') === $time)) {
                return $original;
            }
        }
        if (!str_contains($date, 'T') && !preg_match('/\\d{2}:\\d{2}/', $date)) {
            $parsed = $parsed->setTimeFromTimeString($time ?: ($end ? '23:59:59' : '00:00:00'));
        }

        return $parsed->utc()->toIso8601ZuluString();
    }

    public static function mapInformWith(mixed $value): mixed
    {
        return is_array($value) || $value === null ? $value : ($value === '' ? null : ['authMethodId' => $value]);
    }
    /** Retain native insertion order as well as values for existing signing callers. */
    public function toArray(): array
    {
        $fields = $this->mappedFields();
        $result = $this->original;
        foreach (['id', 'title', 'description', 'note', 'intent', 'status', 'requisition', 'context', 'statusReason', 'category', 'termsOfService', 'author', 'encounter', 'careManager', 'managingOrganization'] as $field) {
            if (array_key_exists($field, $fields) && (!in_array($field, ['author', 'encounter', 'careManager', 'managingOrganization'], true) || $fields[$field] !== null)) {
                $result[$field] = $fields[$field];
            }
        }
        foreach (['addresses', 'supportingInfo', 'contributor', 'basedOn', 'partOf'] as $field) {
            if (array_key_exists($field, $this->input)) {
                $result[$field] = $fields[$field];
            }
        }
        if (array_key_exists('coAuthors', $this->input)) {
            $result['contributor'] = $fields['contributor'];
        }
        if (array_key_exists('episodes', $this->input) || array_key_exists('medicalRecords', $this->input)) {
            $result['supportingInfo'] = $fields['supportingInfo'];
        }
        foreach (['period', 'informWith'] as $field) {
            if (array_key_exists($field, $fields)) {
                $result[$field] = $fields[$field];
            }
        }

        return $result;
    }
}
