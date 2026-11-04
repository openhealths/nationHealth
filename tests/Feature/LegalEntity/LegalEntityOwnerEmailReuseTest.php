<?php

declare(strict_types=1);

namespace Tests\Feature\LegalEntity;

use App\Livewire\LegalEntity\CreateLegalEntity;
use App\Livewire\LegalEntity\Forms\LegalEntitiesForms;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\Relations\Party;
use App\Models\User;
use App\Repositories\Repository;
use App\Rules\InDictionary;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\TestCase;

class LegalEntityOwnerEmailReuseTest extends TestCase
{
    use MocksBasicDictionaries;

    protected function setUp(): void
    {
        parent::setUp();

        new ReflectionProperty(InDictionary::class, 'dictionaryCache')->setValue(null, []);

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'cache.default' => 'array',
            'app.date_format' => 'd.m.Y',
        ]);
        foreach ([
            '2021_01_01_000000_create_persons_table.php',
            '2025_12_10_000012_create_legal_entity_types_table.php',
            '2025_12_10_000014_create_legal_entities_table.php',
            '2025_12_10_000024_create_parties_table.php',
            '2025_12_10_000088_create_users_table.php',
        ] as $migration) {
            (require database_path('migrations/install/' . $migration))->up();
        }
        Http::preventStrayRequests();
        $this->mockBasicDictionaries();
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(InDictionary::class, 'dictionaryCache')->setValue(null, []);

        parent::tearDown();
    }

    #[DataProvider('existingOwners')]
    public function test_existing_owner_email_can_be_reused_for_a_new_legal_entity(
        bool $signedIn,
        string $storedTaxId,
        bool $storedNoTaxId,
        bool $noTaxId,
        string $documentType,
        string $documentNumber
    ): void {
        $component = $this->registration($signedIn, $storedTaxId, $storedNoTaxId);
        $component->legalEntityForm->owner['noTaxId'] = $noTaxId;
        $component->legalEntityForm->owner['taxId'] = $noTaxId ? '' : $storedTaxId;
        $component->legalEntityForm->owner['documents'] = [['type' => $documentType, 'number' => $documentNumber]];

        $this->assertTrue($component->nextStep(2));

        $this->assertFalse($component->getErrorBag()->any());
        $this->assertSame('existing-owner@example.test', $component->legalEntityForm->owner['email']);
        $this->assertSame(1, User::count());
        $this->assertSame($storedTaxId, Party::sole()->taxId);
        Http::assertNothingSent();
    }

    public static function existingOwners(): array
    {
        $cases = [];
        foreach ([false, true] as $signedIn) {
            foreach ([
                'tax id' => ['1234567890', false, false, 'PASSPORT', 'АА123456'],
                'passport' => ['АА123456', true, true, 'PASSPORT', 'АА123456'],
                'id card' => ['123456789', true, true, 'NATIONAL_ID', '123456789'],
                'no tax id selected for an existing login' => ['1234567890', false, true, 'PASSPORT', 'АА123456'],
            ] as $name => $data) {
                $cases[($signedIn ? 'from OUTPATIENT' : 'guest') . ' ' . $name] = [$signedIn, ...$data];
            }
        }

        return $cases;
    }

    #[DataProvider('authenticationContexts')]
    public function test_reusing_an_email_does_not_allow_a_different_tax_id(bool $signedIn): void
    {
        $component = $this->registration($signedIn, '1234567890', false);
        $component->legalEntityForm->owner['taxId'] = '0987654321';

        try {
            $component->nextStep(2);
            $this->fail('A different tax ID must still fail the existing identity validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('legalEntityForm.owner.taxId', $exception->errors());
            $this->assertArrayNotHasKey('legalEntityForm.owner.email', $exception->errors());
        }
    }

    #[DataProvider('authenticationContexts')]
    public function test_reusing_an_email_without_tax_id_still_requires_a_supported_document(bool $signedIn): void
    {
        $component = $this->registration($signedIn, '1234567890', false);
        $component->legalEntityForm->owner['noTaxId'] = true;
        $component->legalEntityForm->owner['documents'] = [];

        try {
            $component->nextStep(2);
            $this->fail('The existing email must not bypass the required identity document.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('legalEntityForm.owner.noTaxId', $exception->errors());
        }
    }

    public static function authenticationContexts(): array
    {
        return ['guest' => [false], 'from OUTPATIENT' => [true]];
    }

    private function registration(bool $signedIn, string $storedTaxId, bool $storedNoTaxId): CreateLegalEntity
    {
        $party = Party::create([
            'last_name' => 'Керівник',
            'first_name' => 'Іван',
            'birth_date' => '1980-01-01',
            'tax_id' => $storedTaxId,
            'no_tax_id' => $storedNoTaxId,
        ]);
        $user = User::create([
            'email' => 'existing-owner@example.test',
            'password' => 'unused-test-password',
            'party_id' => $party->id,
        ]);
        if ($signedIn) {
            $entity = LegalEntity::create([
                'uuid' => (string) Str::uuid(),
                'legal_entity_type_id' => LegalEntityType::whereName(LegalEntity::TYPE_OUTPATIENT)->value('id'),
                'status' => 'ACTIVE',
            ]);
            $this->app->instance('legalEntity', $entity);
            $this->actingAs($user, 'ehealth');
        } else {
            Auth::shouldUse('web');
            $this->assertGuest('web');
        }

        $component = new CreateLegalEntity();
        $component->legalEntityForm = new LegalEntitiesForms($component, 'legalEntityForm');
        $component->boot(Repository::address(), Repository::phone());
        $component->legalEntityForm->type = LegalEntity::TYPE_PRIMARY_CARE;
        $component->legalEntityForm->owner = [
            'lastName' => 'Керівник',
            'firstName' => 'Іван',
            'birthDate' => '01.01.1980',
            'gender' => 'MALE',
            'email' => $user->email,
            'position' => 'P1',
            'phones' => [['type' => 'MOBILE', 'number' => '+380501234567']],
            'documents' => [['type' => 'PASSPORT', 'number' => 'АА123456']],
            'noTaxId' => false,
            'taxId' => $storedTaxId,
        ];

        return $component;
    }
}
