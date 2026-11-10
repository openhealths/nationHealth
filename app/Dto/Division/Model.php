<?php

declare(strict_types=1);

namespace App\Dto\Division;

use App\Classes\eHealth\Api\Responses\Collections\DivisionCreate;
use App\Contracts\Dto\Model as ModelContract;
use App\Dto\Address\Model as AddressData;
use App\Dto\Phone\Model as PhoneData;
use App\Models\Division;
use App\Enums\Status;
use App\Livewire\Division\Forms\DivisionForm;
use App\Dto\FormCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

/**
 * Division form or validated eHealth response mapped to model attributes and nested DTOs.
 * DivisionCreate is used for mapping eHealth response data,
 * DivisionForm is used for mapping form data.
 */
#[Map(source: DivisionCreate::class, if: new SourceClass(DivisionCreate::class))]
#[Map(source: DivisionForm::class, if: new SourceClass(DivisionForm::class), transform: [self::class, 'fromForm'])]
class Model implements ModelContract
{
    #[Map(source: '[uuid]', if: new SourceClass(DivisionCreate::class))]
    #[Map(source: 'division[uuid]', if: new SourceClass(DivisionForm::class), transform: [self::class, 'normalizeUuid'])]
    public ?string $uuid = null;

    #[Map(source: '[name]', if: new SourceClass(DivisionCreate::class))]
    #[Map(source: 'division[name]', if: new SourceClass(DivisionForm::class))]
    public string $name;

    #[Map(source: '[type]', if: new SourceClass(DivisionCreate::class))]
    #[Map(source: 'division[type]', if: new SourceClass(DivisionForm::class))]
    public string $type;

    #[Map(source: '[email]', if: new SourceClass(DivisionCreate::class))]
    #[Map(source: 'division[email]', if: new SourceClass(DivisionForm::class))]
    public string $email;

    #[Map(source: '[status]', if: new SourceClass(DivisionCreate::class))]
    #[Map(source: 'division[uuid]', if: new SourceClass(DivisionForm::class), transform: [self::class, 'formStatus'])]
    public string $status;

    #[Map(source: '[external_id]', if: new SourceClass(DivisionCreate::class))]
    #[Map(source: 'division[externalId]', if: new SourceClass(DivisionForm::class), transform: [self::class, 'normalizeExternalId'])]
    public ?string $externalId = null;

    #[Map(source: '[mountain_group]', if: new SourceClass(DivisionCreate::class), transform: 'boolval')]
    #[Map(source: 'division[mountainGroup]', if: new SourceClass(DivisionForm::class), transform: 'boolval')]
    public bool $mountainGroup = false;

    /** @var array{latitude: float|int|string, longitude: float|int|string}|null */
    #[Map(source: '[location]', if: new SourceClass(DivisionCreate::class))]
    #[Map(source: 'division[location]', if: new SourceClass(DivisionForm::class))]
    public ?array $location = null;

    /** @var array<string, list<array{string, string}>>|null */
    #[Map(source: '[working_hours]', if: new SourceClass(DivisionCreate::class))]
    #[Map(source: 'division[workingHours]', if: new SourceClass(DivisionForm::class))]
    public ?array $workingHours = null;

    #[Map(source: '[dls_id]', if: new SourceClass(DivisionCreate::class))]
    public ?string $dlsId = null;

    #[Map(source: '[dls_verified]', if: new SourceClass(DivisionCreate::class))]
    public ?bool $dlsVerified = null;

    #[Map(source: '[ehealth_inserted_at]', if: new SourceClass(DivisionCreate::class))]
    public ?string $ehealthInsertedAt = null;

    #[Map(source: '[inserted_by]', if: new SourceClass(DivisionCreate::class))]
    public ?string $insertedBy = null;

    #[Map(source: '[ehealth_updated_at]', if: new SourceClass(DivisionCreate::class))]
    public ?string $ehealthUpdatedAt = null;

    #[Map(source: '[updated_by]', if: new SourceClass(DivisionCreate::class))]
    public ?string $updatedBy = null;

    /** @var list<AddressData> */
    #[Map(source: '[addresses]', if: new SourceClass(DivisionCreate::class), transform: new MapCollection(targetClass: AddressData::class))]
    #[Map(source: 'division[addresses]', if: new SourceClass(DivisionForm::class), transform: [[self::class, 'wrapFormItems'], new MapCollection(targetClass: AddressData::class)])]
    public array $addresses = [];

    /** @var list<PhoneData> */
    #[Map(source: '[phones]', if: new SourceClass(DivisionCreate::class), transform: new MapCollection(targetClass: PhoneData::class))]
    #[Map(source: 'division[phones]', if: new SourceClass(DivisionForm::class), transform: [[self::class, 'wrapFormItems'], new MapCollection(targetClass: PhoneData::class)])]
    public array $phones = [];

    #[Map(if: false)]
    protected bool $isForm = false;

    public static function fromForm(self $value, DivisionForm $source): self
    {
        $value->isForm = true;

        return $value;
    }

    public static function formStatus(mixed $uuid): string
    {
        return empty($uuid) ? Status::DRAFT->value : Status::UNSYNCED->value;
    }

    public static function normalizeExternalId(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    public static function normalizeUuid(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * @param  array<string|int, array<string, mixed>>|null  $items
     * @return list<FormCollection>
     */
    public static function wrapFormItems(?array $items): array
    {
        return collect($items)->map(fn (array $item): FormCollection => new FormCollection($item))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toModelAttributes() + [
            'addresses' => array_map(fn (AddressData $address): array => $address->toArray(), $this->addresses),
            'phones' => array_map(fn (PhoneData $phone): array => $phone->toArray(), $this->phones),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'type' => $this->type,
            'email' => $this->email,
            'status' => $this->status,
            'external_id' => $this->externalId,
            'mountain_group' => $this->mountainGroup,
            'location' => $this->location,
            'working_hours' => $this->workingHours,
            'dls_id' => $this->dlsId,
            'dls_verified' => $this->dlsVerified,
            'ehealth_inserted_at' => $this->ehealthInsertedAt,
            'inserted_by' => $this->insertedBy,
            'ehealth_updated_at' => $this->ehealthUpdatedAt,
            'updated_by' => $this->updatedBy,
        ];
    }

    /**
     * Apply the mapped attributes to a Division model in memory.
     *
     * Form data preserves the model's existing eHealth license and audit metadata.
     *
     * @param  Division|null  $division  Target model. Pass null to create a new instance.
     * @return Division The populated target model.
     */
    public function toModel(?Division $division = null): Division
    {
        $division ??= new Division();

        $attributes = $this->toModelAttributes();

        if ($this->isForm) {
            $attributes = Arr::except($attributes, [
                'dls_id',
                'dls_verified',
                'ehealth_inserted_at',
                'inserted_by',
                'ehealth_updated_at',
                'updated_by',
            ]);
        }

        foreach ($attributes as $key => $value) {
            $division->setAttribute(Str::camel($key), $value);
        }

        return $division;
    }
}
