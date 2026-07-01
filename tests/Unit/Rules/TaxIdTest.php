<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Models\Relations\Party;
use App\Models\User;
use App\Rules\TaxId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaxIdTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', [
            '--path' => [
                database_path('migrations'),
                database_path('migrations/install'),
                database_path('migrations/update/0_1'),
            ],
            '--realpath' => true,
        ]);
    }

    public function test_set_data_converts_party_documents_relation_to_array(): void
    {
        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Іван',
            'last_name' => 'Петренко',
        ]);

        $party->documents()->create([
            'type' => 'PASSPORT',
            'number' => 'АА123456',
            'issued_at' => '2020-01-01',
        ]);

        User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'employee@example.test',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $rule = new TaxId();

        $validator = Validator::make(
            [
                'party' => ['taxId' => 'АА123456', 'noTaxId' => true, 'email' => 'employee@example.test'],
            ],
            ['party.taxId' => ['required', 'string', $rule]]
        );

        $this->assertFalse($validator->fails());
    }

    public function test_validates_national_id_from_form_documents_when_no_tax_id(): void
    {
        $rule = new TaxId();

        $validator = Validator::make(
            [
                'party' => ['taxId' => '123456789', 'noTaxId' => true, 'email' => 'unknown-user@example.test'],
                'documents' => [
                    ['type' => 'NATIONAL_ID', 'number' => '123456789'],
                ],
            ],
            ['party.taxId' => ['required', 'string', $rule]]
        );

        $this->assertFalse($validator->fails());
    }
}
