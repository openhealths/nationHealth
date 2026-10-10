<?php

declare(strict_types=1);

namespace App\Dto\Episode;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\FormCollection;
use App\Enums\Episode\Status;
use App\Livewire\Episode\Forms\EpisodeForm as InputForm;
use App\Mapping\Transforms\FhirReference;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

#[Map(source: FormCollection::class, if: new SourceClass(FormCollection::class))]
#[Map(source: InputForm::class, if: new SourceClass(InputForm::class))]
final class Ehealth
{
    use PreservesEhealthDocumentValues;

    #[Map(if: false)]
    public readonly string $id;
    #[Map(source: '[typeCode]', if: new SourceClass(FormCollection::class), transform: [self::class, 'typeValue'])]
    #[Map(source: 'typeCode', if: new SourceClass(InputForm::class), transform: [self::class, 'typeValue'])]
    public array $type;

    #[Map(source: '[name]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'name', if: new SourceClass(InputForm::class))]
    public mixed $name;

    #[Map(if: false)]
    public readonly string $status;
    #[Map(if: false)]
    public readonly array $managingOrganization;
    #[Map(if: false)]
    public readonly array $period;
    #[Map(if: false)]
    public readonly array $careManager;

    public function __construct(string $id, Status $status, string $legalEntity, string $employee, string $periodDate, string $periodStart)
    {
        $this->id = $id;
        $this->status = $status->value;
        $this->managingOrganization = new FhirReference('legal_entity', includeText: true)($legalEntity, $this, null);
        $this->period = ['start' => convertToEHealthISO8601($periodDate.' '.$periodStart)];
        $this->careManager = new FhirReference('employee', includeText: true)($employee, $this, null);
    }

    public static function typeValue(string $value): array
    {
        return ['system' => 'eHealth/episode_types', 'code' => $value];
    }
}
