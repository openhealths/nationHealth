<?php

declare(strict_types=1);

namespace Tests\Feature\Encounter;

use App\Classes\eHealth\Api\Division;
use App\Livewire\Encounter\Concerns\ManagesEncounterReferrals;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Component;
use Tests\TestCase;

class EncounterTransferDivisionLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ehealth.api.domain' => 'https://ehealth.invalid', 'ehealth.api.api_key' => 'test', 'ehealth.api.token' => 'test']);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'source-facility-token');
    }

    public function test_other_facility_is_loaded_through_public_registry_with_its_response_shape(): void
    {
        $destination = (string) Str::uuid();
        $divisionId = (string) Str::uuid();
        $requests = [];
        $api = new Division();
        $api->preventStrayRequests();
        $api->stub(function (Request $request) use (&$requests, $divisionId) {
            $requests[] = $request;

            if (parse_url($request->url(), PHP_URL_PATH) === '/api/divisions') {
                // Administrative Get Divisions cannot return the other facility's rows.
                return Http::response(['data' => []]);
            }

            return Http::response(['data' => [[
                'id' => $divisionId,
                'name' => 'Destination clinic',
                'type' => 'CLINIC',
                'legal_entity' => ['name' => 'Destination facility'],
                'healthcare_services' => [],
            ]], 'paging' => ['page_number' => 1, 'total_pages' => 1]]);
        });
        $this->instance(Division::class, $api);

        $harness = new TransferDivisionLookupHarness();
        $this->assertTrue($harness->loadDestination($destination));
        $this->assertSame([$divisionId], $harness->encounterReferralAllowedDivisionIds);
        $this->assertCount(1, $requests);
        $request = $requests[0];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $this->assertSame('/reports/stats/divisions', parse_url($request->url(), PHP_URL_PATH));
        $this->assertSame($destination, $query['legal_entity_id']);
        $this->assertSame('1', $query['page']);
        $this->assertSame('100', $query['page_size']);
        $this->assertSame(['90', '180', '-90', '-180'], [$query['north'], $query['east'], $query['south'], $query['west']]);
        $this->assertArrayNotHasKey('status', $query);
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer source-facility-token'));
    }

    public function test_pages_filter_explicit_foreign_inactive_and_unsupported_rows(): void
    {
        $destination = (string) Str::uuid();
        $divisionId = (string) Str::uuid();
        $api = new Division();
        $api->preventStrayRequests();
        $pages = [];
        $api->stub(function (Request $request) use (&$pages, $destination, $divisionId) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) $query['page'];
            $pages[] = $page;

            return Http::response(['data' => $page === 1 ? [] : [
                ['id' => $divisionId, 'name' => 'Clinic', 'type' => 'CLINIC', 'legal_entity' => ['id' => $destination]],
                ['id' => (string) Str::uuid(), 'type' => 'CLINIC', 'legal_entity' => ['id' => (string) Str::uuid()]],
                ['id' => (string) Str::uuid(), 'type' => 'CLINIC', 'status' => 'INACTIVE'],
                ['id' => (string) Str::uuid(), 'type' => 'CLINIC', 'is_active' => false],
                ['id' => (string) Str::uuid(), 'type' => 'INVALID'],
                ['id' => 'not-a-uuid', 'type' => 'CLINIC'],
            ], 'paging' => ['page_number' => $page, 'total_pages' => 2]]);
        });
        $this->instance(Division::class, $api);
        $harness = new TransferDivisionLookupHarness();

        $this->assertTrue($harness->loadDestination($destination));
        $this->assertSame([1, 2], $pages);
        $this->assertSame([$divisionId], $harness->encounterReferralAllowedDivisionIds);
    }

    public function test_failed_lookup_clears_stale_permissions_and_returns_loading_error(): void
    {
        $api = new Division();
        $api->preventStrayRequests();
        $api->stub(fn () => Http::response(['error' => ['message' => 'Access denied']], 403));
        $this->instance(Division::class, $api);
        $harness = new TransferDivisionLookupHarness();
        $harness->encounterReferralAllowedDivisionIds = [(string) Str::uuid()];
        $harness->encounterReferralDivisions = [['id' => $harness->encounterReferralAllowedDivisionIds[0]]];

        $this->assertFalse($harness->loadDestination((string) Str::uuid()));
        $this->assertSame([], $harness->encounterReferralAllowedDivisionIds);
        $this->assertSame([], $harness->encounterReferralDivisions);
        $this->assertSame(__('Не вдалося завантажити підрозділи закладу, до якого переводять пацієнта.'), $harness->encounterReferralWarningMessage);
    }

    public function test_empty_successful_lookup_is_distinct_from_request_failure(): void
    {
        $api = new Division();
        $api->preventStrayRequests();
        $api->stub(fn () => Http::response(['data' => []]));
        $this->instance(Division::class, $api);
        $harness = new TransferDivisionLookupHarness();

        $this->assertTrue($harness->loadDestination((string) Str::uuid()));
        $this->assertSame([], $harness->encounterReferralAllowedDivisionIds);
        $this->assertSame('', $harness->encounterReferralWarningMessage);
    }
}

class TransferDivisionLookupHarness extends Component
{
    use ManagesEncounterReferrals;

    public function loadDestination(string $uuid): bool
    {
        return $this->loadEncounterReferralDivisions($uuid);
    }
}
