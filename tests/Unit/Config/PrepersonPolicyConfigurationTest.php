<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\User;
use App\Policies\PrepersonPolicy;
use Illuminate\Auth\Access\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrepersonPolicyConfigurationTest extends TestCase
{
    #[Test]
    public function missingAllowedTypesDenyAccessWithoutBreakingThePage(): void
    {
        $ehealthConfig = config('ehealth');
        unset($ehealthConfig['preperson_legal_entities_allowed_types']);
        config(['ehealth' => $ehealthConfig]);

        $response = $this->checkLegalEntityType('OUTPATIENT');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    #[Test]
    #[DataProvider('configuredTypes')]
    public function configuredTypesPreserveLegalEntityRestrictions(array $allowedTypes, string $type, bool $passes): void
    {
        config(['ehealth.preperson_legal_entities_allowed_types' => $allowedTypes]);

        $response = $this->checkLegalEntityType($type);

        if ($passes) {
            // Passing before() still requires the corresponding ability and user permissions.
            $this->assertNull($response);
        } else {
            $this->assertInstanceOf(Response::class, $response);
            $this->assertTrue($response->denied());
            $this->assertSame(404, $response->status());
        }
    }

    public static function configuredTypes(): array
    {
        return [
            'outpatient' => [['OUTPATIENT', 'EMERGENCY'], 'OUTPATIENT', true],
            'emergency' => [['OUTPATIENT', 'EMERGENCY'], 'EMERGENCY', true],
            'disallowed type' => [['OUTPATIENT', 'EMERGENCY'], 'PRIMARY_CARE', false],
            'empty list' => [[], 'OUTPATIENT', false],
            'configured override' => [['PRIMARY_CARE'], 'PRIMARY_CARE', true],
        ];
    }

    private function checkLegalEntityType(string $type): ?Response
    {
        $legalEntity = new LegalEntity();
        $legalEntity->setRelation('type', new LegalEntityType(['name' => $type]));
        $this->app->instance('legalEntity', $legalEntity);

        return (new PrepersonPolicy())->before(new User(), 'viewAny');
    }
}
