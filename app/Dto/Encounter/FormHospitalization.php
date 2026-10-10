<?php

declare(strict_types=1);

namespace App\Dto\Encounter;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormHospitalization
{
    #[Map(source: '[pre_admission_identifier?]', if: new SourceHasPath('pre_admission_identifier'))]
    public mixed $preAdmissionIdentifier = '';

    #[Map(source: '[admit_source?][code?]', if: new SourceHasPath('admit_source.code'))]
    public mixed $admitSource = '';

    #[Map(source: '[re_admission?][code?]', if: new SourceHasPath('re_admission.code'))]
    public mixed $reAdmission = '';

    #[Map(source: '[destination?][identifier?][value?]', if: new SourceHasPath('destination.identifier.value'))]
    public mixed $destination = '';

    #[Map(source: '[discharge_disposition?][code?]', if: new SourceHasPath('discharge_disposition.code'))]
    public mixed $dischargeDisposition = '';

    #[Map(source: '[discharge_department?][code?]', if: new SourceHasPath('discharge_department.code'))]
    public mixed $dischargeDepartment = '';

    public function toArray(): array
    {
        $data = get_object_vars($this);
        unset($data['detailsMap'], $data['fallbackTime']);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = array_map(static fn (mixed $row): mixed => is_object($row) ? (method_exists($row, 'toArray') ? $row->toArray() : get_object_vars($row)) : $row, $value);
            }
        }

        return $data;
    }
}
