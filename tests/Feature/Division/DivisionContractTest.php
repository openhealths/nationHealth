<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Models\Division;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DivisionContractTest extends TestCase
{
    use DivisionMappingFixtures;

    public static function localExternalIds(): array
    {
        return [
            'absent' => [[], null],
            'null' => [['externalId' => null], null],
            'empty' => [['externalId' => ''], null],
            'zero' => [['externalId' => '0'], '0'],
            'leading zeros' => [['externalId' => '00123'], '00123'],
        ];
    }

    #[DataProvider('localExternalIds')]
    public function test_local_form_snapshot_replaces_external_id_instead_of_applying_patch_semantics(
        array $input,
        ?string $expected
    ): void {
        $component = $this->formComponent();
        unset($component->divisionForm->division['externalId']);
        $component->divisionForm->division = array_replace($component->divisionForm->division, $input);
        $division = new Division(['external_id' => 'OLD-ID']);

        $this->mapForm($component->divisionForm)->toModel($division);

        $this->assertSame($expected, $division->externalId);
    }

    public function test_form_cannot_replace_any_ehealth_metadata_or_local_timestamps(): void
    {
        $metadata = [
            'dls_id' => 'original-license',
            'dls_verified' => false,
            'ehealth_inserted_at' => '2026-10-01',
            'inserted_by' => 'original-author',
            'ehealth_updated_at' => '2026-10-02',
            'updated_by' => 'original-editor',
            'created_at' => '2026-10-01 10:00:00',
            'updated_at' => '2026-10-02 11:00:00',
        ];
        $component = $this->formComponent(array_fill_keys([
            'dlsId', 'dlsVerified', 'ehealthInsertedAt', 'insertedBy',
            'ehealthUpdatedAt', 'updatedBy', 'createdAt', 'updatedAt',
        ], 'untrusted'));
        $division = new Division($metadata);
        $division->setRawAttributes($metadata);

        $this->mapForm($component->divisionForm)->toModel($division);

        $this->assertSame($metadata, array_intersect_key($division->getAttributes(), $metadata));
    }

    public static function optionalResponseFields(): array
    {
        return [
            'absent' => [false],
            'explicit null' => [true],
        ];
    }

    #[DataProvider('optionalResponseFields')]
    public function test_single_api_snapshot_resets_optional_values_on_an_existing_model(bool $explicitNull): void
    {
        $fields = [
            'external_id', 'location', 'working_hours', 'dls_id', 'dls_verified',
            'inserted_at', 'inserted_by', 'updated_at', 'updated_by',
        ];
        $response = $this->response();
        foreach ($fields as $field) {
            unset($response[$field]);
        }
        if ($explicitNull) {
            $response = array_replace($response, array_fill_keys($fields, null));
        }
        $division = new Division([
            'external_id' => 'OLD-ID', 'dls_id' => 'old-license', 'dls_verified' => true,
            'ehealth_inserted_at' => '2026-10-01', 'inserted_by' => 'old-author',
            'ehealth_updated_at' => '2026-10-02', 'updated_by' => 'old-editor',
        ]);
        $data = $this->mapResponse($response);

        $data->toModel($division);

        foreach (['externalId', 'location', 'workingHours', 'dlsId', 'dlsVerified',
            'ehealthInsertedAt', 'insertedBy', 'ehealthUpdatedAt', 'updatedBy'] as $property) {
            $this->assertNull($data->$property, $property);
        }
        foreach (['external_id', 'dls_id', 'dls_verified', 'ehealth_inserted_at',
            'inserted_by', 'ehealth_updated_at', 'updated_by'] as $attribute) {
            $this->assertNull($division->getAttributes()[$attribute], $attribute);
        }
    }
}
