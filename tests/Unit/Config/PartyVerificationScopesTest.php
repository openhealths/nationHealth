<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PartyVerificationScopesTest extends TestCase
{
    #[Test]
    public function legal_entity_type_scopes_include_party_verification_read(): void
    {
        $scopes = collect(config('ehealth.legal_entity_types'))
            ->flatten()
            ->unique()
            ->values();

        // Upstream requests party_verification:read for every legal entity type (OAuth base scopes).
        $this->assertTrue(
            $scopes->contains('party_verification:read'),
            'party_verification:read must be requested for legal entity types'
        );
        $this->assertTrue($scopes->contains('party_verification:details'));
        $this->assertTrue($scopes->contains('party_verification:write'));

        foreach (array_keys(config('ehealth.legal_entity_types')) as $type) {
            $typeScopes = config("ehealth.legal_entity_types.{$type}");
            $this->assertContains(
                'party_verification:read',
                $typeScopes,
                "party_verification:read must be present for legal entity type {$type}"
            );
        }
    }

    #[Test]
    public function hr_role_includes_party_verification_read(): void
    {
        $scopes = config('ehealth.roles.HR');

        $this->assertIsArray($scopes, 'Role HR must be configured');
        $this->assertContains(
            'party_verification:read',
            $scopes,
            'party_verification:read must be present for HR role'
        );
    }

    #[Test]
    public function owner_and_reorganization_owner_include_party_verification_read(): void
    {
        foreach (['OWNER', 'REORGANIZATION_OWNER'] as $role) {
            $scopes = config("ehealth.roles.{$role}");

            $this->assertIsArray($scopes, "Role {$role} must be configured");
            $this->assertContains(
                'party_verification:read',
                $scopes,
                "party_verification:read must be present for {$role} role"
            );
        }
    }

    #[Test]
    public function non_bulk_roles_do_not_include_party_verification_read(): void
    {
        // Bulk list is gated by OAuth token scope; only HR / OWNER / REORGANIZATION_OWNER advertise :read.
        $rolesWithoutBulkRead = collect(config('ehealth.roles'))
            ->keys()
            ->diff(['HR', 'OWNER', 'REORGANIZATION_OWNER']);

        foreach ($rolesWithoutBulkRead as $role) {
            $scopes = config("ehealth.roles.{$role}");

            $this->assertIsArray($scopes);
            $this->assertNotContains(
                'party_verification:read',
                $scopes,
                "party_verification:read must not be present for role {$role}"
            );
        }
    }
}
