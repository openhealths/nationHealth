<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Core\Arr;
use App\Services\MedicalEvents\InformWith;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** ESOZ clinical profile. IL transport is produced only by the explicit API adapter. */
class MedicationRequestMapper implements FhirMapperContract
{
    public function toFhir(array $data, mixed ...$context): array
    {
        return $this->mapForm($data, $context[0] ?? [], true);
    }

    private function mapForm(array $data, array $options, bool $requireId): array
    {
        $input = Arr::toCamelCase($data);
        $resource = $options['resource'] ?? [];
        $id = $input['uuid'] ?? $input['id'] ?? $resource['id'] ?? null;
        if ($requireId && (!is_string($id) || $id === '')) {
            throw new InvalidArgumentException('Medication mapping requires an allocated resource id.');
        }
        if ($id !== null) {
            $resource['id'] = $id;
        }
        foreach (['status', 'intent', 'priority', 'requestNumber', 'note', 'basedOn', 'context', 'subject', 'requester', 'division', 'program', 'medicationReference', 'quantity', 'period', 'createdAt', 'priorPrescription', 'containerDosage', 'informWith'] as $field) {
            if (array_key_exists($field, $input)) {
                $this->set($resource, $field, $input[$field]);
            }
        }
        foreach (['medicationId' => ['medicationReference', 'medication'], 'medicationProgramId' => ['program', 'medical_program']] as $source => [$target, $type]) {
            if (array_key_exists($source, $input)) {
                $this->set($resource, $target, $input[$source] ? $this->reference($input[$source], $type, $resource[$target] ?? null) : null);
            }
        }
        foreach (['person_uuid' => ['subject', 'patient'], 'employee_uuid' => ['requester', 'employee'], 'division_uuid' => ['division', 'division'], 'encounter_uuid' => ['context', 'encounter']] as $source => [$target, $type]) {
            if (!empty($options[$source])) {
                $resource[$target] = $this->reference($options[$source], $type, $resource[$target] ?? null);
            }
        }
        if (array_key_exists('contextUuid', $input)) {
            $this->set($resource, 'context', $input['contextUuid'] ? $this->reference($input['contextUuid'], 'encounter', $resource['context'] ?? null) : null);
        }
        if (array_key_exists('category', $input)) {
            $this->set($resource, 'category', is_array($input['category']) || $input['category'] === null ? $input['category'] : $this->concept($input['category'], 'MEDICATION_REQUEST_CATEGORY'));
        }
        if (array_key_exists('medicationQty', $input)) {
            $this->set($resource, 'quantity', $input['medicationQty'] === null ? null : array_replace($resource['quantity'] ?? [], ['value' => $this->number($input['medicationQty'])]));
        }
        foreach (['startedAt' => 'start', 'endedAt' => 'end'] as $source => $target) {
            if (array_key_exists($source, $input)) {
                $resource['period'] ??= [];
                $this->set($resource['period'], $target, $input[$source]);
            }
        }
        if (array_key_exists('basedOnUuid', $input)) {
            $this->replaceBasis($resource, $input['basedOnUuid'], 'activity');
        }
        if (array_key_exists('carePlanUuid', $options)) {
            $this->replaceBasis($resource, $options['carePlanUuid'], 'care_plan');
        }
        if (array_key_exists('dosageInstructions', $input)) {
            $resource['dosageInstruction'] = array_map($this->dosage(...), array_values($input['dosageInstructions'] ?? []));
        } elseif (array_key_exists('dosageInstruction', $input)) {
            $resource['dosageInstruction'] = array_map($this->dosage(...), array_values($input['dosageInstruction'] ?? []));
        }

        return $resource;
    }

    /** Preserve all clinical datatypes; scalar projections never replace the original resource. */
    public function fromFhir(array $data, mixed ...$context): array
    {
        $form = array_intersect_key($data, array_flip(['status', 'intent', 'priority', 'note', 'containerDosage', 'informWith', 'createdAt', 'basedOn', 'context', 'subject', 'requester', 'division', 'program', 'priorPrescription']));
        $form['uuid'] = $data['id'];
        $form['category'] = $data['category'] ?? null;
        $form['requestNumber'] = $data['requestNumber'] ?? null;
        $form['medicationId'] = data_get($data, 'medicationReference.identifier.value');
        $form['medicationQty'] = data_get($data, 'quantity.value');
        $form['medicationProgramId'] = data_get($data, 'program.identifier.value');
        $form['startedAt'] = data_get($data, 'period.start');
        $form['endedAt'] = data_get($data, 'period.end');
        $form['contextUuid'] = data_get($data, 'context.identifier.value');
        $form['basedOnUuid'] = null;
        foreach ($data['basedOn'] ?? [] as $reference) {
            if ($this->referenceType($reference) === 'activity') {
                $form['basedOnUuid'] = $reference['identifier']['value'];
                break;
            }
        }
        $form['dosageInstructions'] = $data['dosageInstruction'] ?? [];

        return Arr::toSnakeCase($form);
    }

    /** Import a complete native IL detail resource, including unknown metadata. */
    public function fromEHealth(array $data): array
    {
        $resource = Arr::toCamelCase($data);
        if (isset($resource['division']['id'])) {
            $resource['divisionDetails'] = $resource['division'];
        }
        foreach (['person' => ['subject', 'patient'], 'employee' => ['requester', 'employee'], 'division' => ['division', 'division'], 'medicalProgram' => ['program', 'medical_program']] as $source => [$target, $type]) {
            $value = $data[Str::snake($source).'_id'] ?? data_get($resource, $source.'.id');
            if ($value !== null) {
                $resource[$target] = $this->reference($value, $type);
            }
        }
        $medicationId = $resource['medicationId'] ?? data_get($resource, 'medicationInfo.medicationId');
        if ($medicationId !== null) {
            $resource['medicationReference'] = $this->reference($medicationId, 'medication');
        }
        $quantity = $resource['medicationQty'] ?? data_get($resource, 'medicationInfo.medicationQty');
        if ($quantity !== null) {
            $resource['quantity'] = ['value' => $this->number($quantity)];
        }
        foreach (['startedAt' => 'start', 'endedAt' => 'end'] as $source => $target) {
            if (array_key_exists($source, $resource)) {
                $resource['period'][$target] = $resource[$source];
            }
        }
        if (isset($resource['category']) && is_string($resource['category'])) {
            $resource['category'] = $this->concept($resource['category'], 'MEDICATION_REQUEST_CATEGORY');
        }
        if (isset($resource['dosageInstruction'])) {
            $resource['dosageInstruction'] = array_map($this->dosage(...), $resource['dosageInstruction']);
        }
        foreach (['personId', 'employeeId', 'divisionId', 'medicalProgramId', 'medicationId', 'medicationQty', 'startedAt', 'endedAt'] as $field) {
            unset($resource[$field]);
        }
        unset($resource['seed']); // Only an untouched, freshly fetched native detail may be signed.

        return $resource;
    }

    /** Create has a distinct IL schema; generic FHIR must never be sent to this endpoint. */
    public function toCreateRequestPayload(array $data, array $uuids, ?string $carePlanUuid = null): array
    {
        $options = $uuids;
        if ($carePlanUuid !== null) {
            $options['carePlanUuid'] = $carePlanUuid;
        }
        $resource = $this->mapForm($data, $options, false);
        $request = [];
        foreach (['subject' => 'person_id', 'requester' => 'employee_id', 'division' => 'division_id', 'medicationReference' => 'medication_id', 'program' => 'medical_program_id'] as $source => $target) {
            $value = data_get($resource, $source.'.identifier.value');
            if ($value !== null) {
                $request[$target] = $value;
            }
        }
        foreach (['intent', 'priority', 'note', 'basedOn', 'context', 'priorPrescription', 'containerDosage'] as $field) {
            if (array_key_exists($field, $resource)) {
                $request[Str::snake($field)] = Arr::toSnakeCase([$field => $resource[$field]])[Str::snake($field)];
            }
        }
        $request['intent'] ??= 'order';
        $request['category'] = data_get($resource, 'category.coding.0.code', 'community');
        if (isset($resource['quantity']['value'])) {
            $request['medication_qty'] = $this->number($resource['quantity']['value']);
        }
        foreach (['createdAt' => 'created_at', 'period.start' => 'started_at', 'period.end' => 'ended_at'] as $source => $target) {
            $value = data_get($resource, $source);
            if ($value !== null) {
                $request[$target] = $this->date($value);
            }
        }
        if (isset($resource['dosageInstruction'])) {
            $request['dosage_instruction'] = array_map(function (array $dosage): array {
                $this->assertDosage($dosage);
                if (isset($dosage['doseAndRate'])) {
                    if (count($dosage['doseAndRate']) !== 1) {
                        throw new InvalidArgumentException('ESOZ IL accepts exactly one doseAndRate object per instruction.');
                    }
                    $dosage['doseAndRate'] = $dosage['doseAndRate'][0];
                }

                return Arr::toSnakeCase($dosage);
            }, $resource['dosageInstruction']);
        }
        $authMethodId = InformWith::authMethodId($resource['informWith'] ?? null);
        if ($authMethodId !== null) {
            $request['inform_with'] = $authMethodId;
        }
        if (isset($request['container_dosage']) && is_string($request['container_dosage'])) {
            $parts = explode('|', $request['container_dosage']);
            if (count($parts) !== 3 || $parts[2] === '') {
                throw new InvalidArgumentException('Container dosage requires an explicit medication unit code.');
            }
            $request['container_dosage'] = ['value' => $this->number($parts[0]), 'system' => 'MEDICATION_UNIT', 'code' => $parts[2]];
        }

        return ['medication_request_request' => $request];
    }

    public function toPrequalifyPayload(array $data, array $uuids, ?string $carePlanUuid = null): array
    {
        $payload = $this->toCreateRequestPayload($data, $uuids, $carePlanUuid);
        $programId = $payload['medication_request_request']['medical_program_id'] ?? null;
        unset($payload['medication_request_request']['medical_program_id']);
        $payload['programs'] = $programId === null ? [] : [['id' => $programId]];

        return $payload;
    }

    /** Local reconstruction cannot produce the server's seed or signed detail contract. */
    public function toCreateSignedContent(array $data, array $uuids, ?string $carePlanUuid = null): array
    {
        throw new InvalidArgumentException('Sign the fresh native MRR detail returned by ESOZ, never mapper output.');
    }

    private function dosage(array $input): array
    {
        $dosage = Arr::toCamelCase($input);
        foreach (['route' => 'route_codes', 'method' => 'administration_methods'] as $field => $dictionary) {
            if (isset($dosage[$field]) && is_string($dosage[$field])) {
                $code = $field === 'route' && $dosage[$field] === 'oral' ? '26643006' : $dosage[$field];
                $dosage[$field] = $this->concept($code, 'eHealth/SNOMED/'.$dictionary);
            }
        }
        if (isset($dosage['additionalInstructionCode'])) {
            $dosage['additionalInstruction'] = [$this->concept($dosage['additionalInstructionCode'], 'eHealth/SNOMED/additional_dosage_instructions')];
            unset($dosage['additionalInstructionCode']);
        }
        if (isset($dosage['doseAndRate'])) {
            $items = array_is_list($dosage['doseAndRate']) ? $dosage['doseAndRate'] : [$dosage['doseAndRate']];
            $dosage['doseAndRate'] = array_map(function (array $item): array {
                if (array_key_exists('doseQuantityValue', $item)) {
                    $quantity = ['value' => $this->number($item['doseQuantityValue'])];
                    foreach (['unit', 'system', 'code', 'comparator'] as $field) {
                        if (array_key_exists('doseQuantity'.ucfirst($field), $item)) {
                            $quantity[$field] = $item['doseQuantity'.ucfirst($field)];
                        }
                        unset($item['doseQuantity'.ucfirst($field)]);
                    }
                    unset($item['doseQuantityValue']);
                    $item['doseQuantity'] = $quantity;
                }

                return $item;
            }, $items);
        }

        return $dosage;
    }

    public function medicationUnit(array $medication): string
    {
        foreach ($medication['ingredients'] ?? [] as $ingredient) {
            $unit = data_get($ingredient, 'dosage.denumerator_unit');
            if (is_string($unit) && trim($unit) !== '') {
                return $unit;
            }
        }

        foreach ($medication['packages'] ?? [] as $package) {
            $unit = data_get($package, 'container_dosage.numerator_unit');
            if (is_string($unit) && trim($unit) !== '') {
                return $unit;
            }
        }

        throw new InvalidArgumentException(__('care-plan.medication_dosage_unit_unavailable'));
    }

    public function dosageInstructionsFromForm(array $formData, array $medication): array
    {
        $unit = $this->medicationUnit($medication);
        $administration = $this->number($formData['max_dose_per_administration'] ?? null);
        $daily = $this->number($formData['max_dose_per_period'] ?? null);
        if ($administration <= 0 || $daily <= 0) {
            throw new InvalidArgumentException(__('care-plan.eprescription_dose_required'));
        }

        $quantity = static fn (float $value): array => [
            'value' => $value, 'unit' => $unit, 'system' => 'MEDICATION_UNIT', 'code' => $unit,
        ];
        $instruction = array_filter([
            'sequence' => 1,
            'text' => $formData['signature_text'] ?? null,
            'patient_instruction' => $formData['patient_instruction'] ?? null,
            'route' => $formData['route'] ?? null,
            'as_needed_boolean' => $formData['as_needed_boolean'] ?? null,
            'as_needed_codeable_concept' => $formData['as_needed_codeable_concept'] ?? null,
            'dose_and_rate' => $formData['dose_and_rate'] ?? null,
            'max_dose_per_administration' => $quantity($administration),
            'max_dose_per_period' => [
                'numerator' => $quantity($daily),
                'denominator' => ['value' => 1, 'unit' => 'd', 'system' => 'eHealth/ucum/units', 'code' => 'd'],
            ],
        ], static fn ($value) => $value !== null && $value !== '');
        if (!array_key_exists('as_needed_boolean', $instruction) && !array_key_exists('as_needed_codeable_concept', $instruction)) {
            $instruction['as_needed_boolean'] = false;
        }

        return [$instruction];
    }

    private function assertDosage(array $dosage): void
    {
        foreach (['sequence', 'text', 'maxDosePerPeriod', 'maxDosePerAdministration'] as $field) {
            if (!array_key_exists($field, $dosage)) {
                throw new InvalidArgumentException('Structured dosage requires '.$field.'.');
            }
        }
        foreach (['maxDosePerPeriod', 'maxDosePerAdministration', 'maxDosePerLifetime'] as $field) {
            if (array_key_exists($field, $dosage) && !is_array($dosage[$field])) {
                throw new InvalidArgumentException(__('care-plan.medication_dosage_structure_required', [
                    'field' => __('care-plan.medication_dosage_fields.'.$field),
                ]));
            }
        }
        if (!is_int($dosage['sequence']) || $dosage['sequence'] < 0 || !is_string($dosage['text']) || trim($dosage['text']) === '') {
            throw new InvalidArgumentException('Dosage requires an explicit sequence and non-empty text.');
        }
        if (!isset($dosage['maxDosePerAdministration']['value'], $dosage['maxDosePerPeriod']['numerator'], $dosage['maxDosePerPeriod']['denominator'])) {
            throw new InvalidArgumentException('Maximum dosage Quantity and Ratio must be complete.');
        }
        if (array_key_exists('asNeededBoolean', $dosage) === array_key_exists('asNeededCodeableConcept', $dosage)) {
            throw new InvalidArgumentException('Specify exactly one asNeeded choice.');
        }
        if (isset($dosage['asNeededBoolean']) && !is_bool($dosage['asNeededBoolean'])) {
            throw new InvalidArgumentException('asNeededBoolean must be a boolean.');
        }
        if (array_key_exists('asNeededCodeableConcept', $dosage) && !is_array($dosage['asNeededCodeableConcept'])) {
            throw new InvalidArgumentException('asNeededCodeableConcept must be a complete concept.');
        }
        $this->assertQuantities($dosage);
        if (isset($dosage['doseAndRate'])) {
            foreach ($dosage['doseAndRate'] as $item) {
                $this->assertChoice($item, ['doseQuantity', 'doseRange']);
                $this->assertChoice($item, ['rateQuantity', 'rateRange', 'rateRatio']);
            }
        }
    }

    private function assertChoice(array $data, array $fields): void
    {
        if (count(array_intersect(array_keys($data), $fields)) !== 1) {
            throw new InvalidArgumentException('Conflicting FHIR choice fields: '.implode(', ', $fields));
        }
    }

    private function assertQuantities(array $data): void
    {
        if (array_key_exists('value', $data)) {
            $this->number($data['value']);
            if (!is_int($data['value']) && !is_float($data['value'])) {
                throw new InvalidArgumentException('Structured Quantity.value must be a JSON number.');
            }
            if (!is_string($data['unit'] ?? null) || trim($data['unit']) === '') {
                throw new InvalidArgumentException('ESOZ SimpleQuantity requires an explicit unit.');
            }
        }
        if (isset($data['numerator']) && (!isset($data['denominator']['value']) || (float) $data['denominator']['value'] <= 0)) {
            throw new InvalidArgumentException('Ratio requires an explicit positive denominator.');
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $this->assertQuantities($value);
            }
        }
    }

    public function referenceType(array $reference): ?string
    {
        foreach (data_get($reference, 'identifier.type.coding', []) as $coding) {
            if (($coding['system'] ?? null) === 'eHealth/resources') {
                return ($coding['code'] ?? null) === 'care_plan_activity' ? 'activity' : ($coding['code'] ?? null);
            }
        }

        return null;
    }

    private function replaceBasis(array &$resource, ?string $id, string $type): void
    {
        $existing = collect($resource['basedOn'] ?? [])->first(fn (array $reference): bool => $this->referenceType($reference) === $type);
        if (data_get($existing, 'identifier.value') === $id) {
            return;
        }
        $items = array_values(array_filter($resource['basedOn'] ?? [], fn (array $reference): bool => $this->referenceType($reference) !== $type));
        if ($id !== null && $id !== '') {
            $items[] = $this->reference($id, $type);
        }
        $resource['basedOn'] = $items;
    }

    private function reference(string $id, string $type, ?array $existing = null): array
    {
        if (data_get($existing, 'identifier.value') === $id) {
            return $existing;
        }

        return ['identifier' => ['type' => $this->concept($type, 'eHealth/resources'), 'value' => $id]];
    }

    private function concept(string $code, string $system): array
    {
        return ['coding' => [['system' => $system, 'code' => $code]]];
    }

    private function set(array &$data, string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            unset($data[$field]);
        } else {
            $data[$field] = $value;
        }
    }

    private function date(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new InvalidArgumentException('Medication dates must be explicit valid calendar dates (YYYY-MM-DD).');
        }

        return $value;
    }

    private function number(mixed $value): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || !BigDecimal::of((string) $value)->isEqualTo((string) (float) $value)) {
            throw new InvalidArgumentException('Medication quantity cannot be represented without loss.');
        }

        return (float) $value;
    }
}
