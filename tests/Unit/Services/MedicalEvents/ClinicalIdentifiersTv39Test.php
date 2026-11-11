<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Models\Employee\Employee;
use App\Models\User;
use App\Services\MedicalEvents\EncounterPackageBuilder;
use App\Services\MedicalEvents\Mappers\ConditionMapper;
use App\Services\MedicalEvents\Mappers\DetectedIssueMapper;
use App\Services\MedicalEvents\Mappers\DeviceMapper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class ClinicalIdentifiersTv39Test extends TestCase
{
    public static function resources(): array
    {
        return [
            'device' => [DeviceMapper::class, ['primarySource' => true, 'typeCode' => 'walker']],
            'detected issue' => [DetectedIssueMapper::class, ['primarySource' => true, 'subjectId' => '07132850-201f-4fb3-bb30-9e39c7255a2b']],
            'condition' => [ConditionMapper::class, ['primarySource' => true, 'codeSystem' => 'eHealth/ICD10_AM/condition_codes', 'codeCode' => 'A00.0',
                'clinicalStatus' => 'active', 'verificationStatus' => 'confirmed', 'onsetDate' => '2026-11-11', 'onsetTime' => '09:00']],
        ];
    }

    #[DataProvider('resources')]
    public function test_new_ids_are_unique_uuidv1_and_do_not_replace_existing_ids(string $mapperClass, array $data): void
    {
        $context = ['encounter' => (string) Str::uuid(), 'employee' => (string) Str::uuid()];
        $mapper = new $mapperClass();
        $first = $mapper->toFhir($data, $context);
        $second = $mapper->toFhir($data, $context);
        $this->assertSame(1, Uuid::fromString($first['id'])->getVersion());
        $this->assertSame(1, Uuid::fromString($second['id'])->getVersion());
        $this->assertNotSame($first['id'], $second['id']);
        $existing = (string) Str::uuid();
        $this->assertSame($existing, $mapper->toFhir($data + ['uuid' => $existing], $context)['id']);
    }

    public function test_new_package_ids_use_version_one_and_preserve_the_existing_episode(): void
    {
        $employee = new Employee(['uuid' => (string) Str::uuid()]);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('getEncounterWriterEmployee')->with('AMB')->twice()->andReturn($employee);
        Auth::shouldReceive('user')->twice()->andReturn($user);
        $builder = new ClinicalIdentifierPackageHarness();
        $new = $builder->build(['encounter' => ['classCode' => 'AMB'], 'episode' => ['id' => null]], 'existing');
        foreach (['encounter', 'visit', 'episode'] as $key) {
            $this->assertSame(1, Uuid::fromString($new['identifiers'][$key])->getVersion());
        }
        $this->assertCount(3, array_unique(array_intersect_key($new['identifiers'], array_flip(['encounter', 'visit', 'episode']))));
        $existing = (string) Str::uuid();
        $preserved = $builder->build(['encounter' => ['classCode' => 'AMB'], 'episode' => ['id' => $existing]], 'existing');
        $this->assertSame($existing, $preserved['identifiers']['episode']);
        $this->assertSame($employee->uuid, $preserved['identifiers']['employee']);
    }
}

class ClinicalIdentifierPackageHarness extends EncounterPackageBuilder
{
    public function toFhir(array $data, array $uuids): array
    {
        return ['identifiers' => $uuids];
    }
}
