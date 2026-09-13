<?php

declare(strict_types=1);

namespace Tests\Unit\Composition;

use App\Services\MedicalEvents\Mappers\CompositionMapper;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pins the createComposition payload to the SwaggerHub contract 2.39.2.
 *
 * The previous payload builder got seven things wrong at once, so each is asserted
 * separately here rather than through one golden-array comparison — a single failing test
 * name should say which part of the contract broke.
 */
class CompositionMapperTest extends TestCase
{
    private const string AUTHOR = '43cc2161-1c2b-481b-a618-77e35817f850';

    private const string SUBJECT = '52b504c7-0177-4078-834b-52d89154081c';

    private const string ENCOUNTER = 'e39ee5ae-2644-4f04-8e64-bb359866e907';

    private const string AUTH_METHOD = 'e7ff2eef-712f-4676-960d-6aa16dce2103';

    #[Test]
    public function coding_systems_are_the_bare_dictionary_names(): void
    {
        $payload = $this->tempDisability();

        $this->assertSame('COMPOSITION_TYPES', $payload['type']['coding'][0]['system']);
        $this->assertSame('COMPOSITION_CATEGORIES', $payload['category']['coding'][0]['system']);
        $this->assertSame('COMPOSITION_EVENTS', $payload['event'][0]['code']['coding'][0]['system']);
        $this->assertSame('eHealth/resources', $payload['subject']['type']['coding'][0]['system']);
    }

    #[Test]
    public function event_is_a_list_whose_code_is_a_single_codeable_concept(): void
    {
        $payload = $this->tempDisability();

        $this->assertArrayHasKey(0, $payload['event'], 'event must be a list of event objects.');
        $this->assertSame('COMPOSITION_VALIDITY_PERIOD', $payload['event'][0]['code']['coding'][0]['code']);
        $this->assertArrayHasKey('period', $payload['event'][0]);
    }

    #[Test]
    public function references_are_bare_resource_identifiers_without_an_identifier_wrapper(): void
    {
        $payload = $this->tempDisability();

        foreach (['subject', 'encounter', 'author'] as $reference) {
            $this->assertArrayNotHasKey(
                'identifier',
                $payload[$reference],
                "$reference must not be nested under an identifier key."
            );
            $this->assertArrayHasKey('value', $payload[$reference]);
            $this->assertArrayHasKey('type', $payload[$reference]);
        }

        $this->assertArrayNotHasKey('identifier', $payload['section']['focus']);
        $this->assertSame(self::SUBJECT, $payload['subject']['value']);
        $this->assertSame(self::ENCOUNTER, $payload['encounter']['value']);
        $this->assertSame(self::AUTHOR, $payload['author']['value']);
    }

    /**
     * The fixed times mark start and end of day rather than real instants, so converting
     * the date to UTC would move it to the previous day on any positive offset — which is
     * what Kyiv time is all year round.
     */
    #[Test]
    public function the_validity_period_keeps_the_chosen_calendar_date(): void
    {
        config(['app.timezone' => 'Europe/Kyiv']);
        date_default_timezone_set('Europe/Kyiv');

        $payload = $this->tempDisability([
            'eventPeriodStart' => '2026-08-13',
            'eventPeriodEnd' => '2026-08-20',
        ]);

        $this->assertSame('2026-08-13T00:00:01Z', $payload['event'][0]['period']['start']);
        $this->assertSame('2026-08-20T20:59:59Z', $payload['event'][0]['period']['end']);
    }

    #[Test]
    public function extensions_are_flat_pairs_of_a_code_and_a_typed_value(): void
    {
        $payload = $this->tempDisability([
            'informWithUuid' => self::AUTH_METHOD,
            'isAccident' => true,
            'isIntoxicated' => true,
            'treatmentViolation' => 'reject_recommendation',
            'treatmentViolationDate' => '2026-08-15',
        ]);

        $byCode = collect($payload['extension'])->keyBy('valueCode');

        $this->assertSame(self::AUTH_METHOD, ($byCode['AUTHORIZE_WITH'] ?? $byCode['INFORM_WITH'])['valueUuid']);
        $this->assertTrue($byCode['IS_ACCIDENT']['valueBoolean']);
        $this->assertTrue($byCode['IS_INTOXICATED']['valueBoolean']);
        $this->assertSame('reject_recommendation', $byCode['TREATMENT_VIOLATION']['valueString']);
        $this->assertSame('2026-08-15', $byCode['TREATMENT_VIOLATION_DATE']['valueDate']);
    }

    #[Test]
    public function unset_optional_extensions_are_omitted_entirely(): void
    {
        $payload = $this->tempDisability();

        $this->assertSame([], $payload['extension']);
        $this->assertArrayNotHasKey('relatesTo', $payload);
    }

    #[Test]
    public function a_violation_date_is_dropped_when_no_violation_was_recorded(): void
    {
        $payload = $this->tempDisability(['treatmentViolationDate' => '2026-08-15']);

        $this->assertSame([], $payload['extension']);
    }

    #[Test]
    public function an_unidentified_patient_is_referenced_as_a_preperson(): void
    {
        $payload = $this->tempDisability(['isUnidentified' => true]);

        $this->assertSame('preperson', $payload['subject']['type']['coding'][0]['code']);
        $this->assertSame('preperson', $payload['section']['focus']['type']['coding'][0]['code']);
    }

    #[Test]
    public function a_refining_conclusion_points_at_the_one_it_replaces(): void
    {
        $previous = '5f21c9a6-0000-4000-8000-000000000001';

        $payload = $this->tempDisability(['relatesToTargetUuid' => $previous]);

        $this->assertSame('replaces', $payload['relatesTo']['code']);
        $this->assertSame($previous, $payload['relatesTo']['targetIdentifier']['value']);
        $this->assertSame(
            'composition',
            $payload['relatesTo']['targetIdentifier']['type']['coding'][0]['code']
        );
    }

    #[Test]
    public function a_birth_conclusion_has_an_open_ended_period_starting_on_the_birth_date(): void
    {
        $payload = $this->mapper()->newborn([
            'category' => 'LIVE_BIRTH',
            'prepersonUuid' => 'c7c41d7e-f0e5-4118-b5be-fedfb5a1e8ed',
            'encounterUuid' => self::ENCOUNTER,
            'personUuid' => self::SUBJECT,
            'newbornBirthDate' => '2026-08-13',
            'newbornSex' => 'MALE',
        ], self::AUTHOR);

        $this->assertSame('2026-08-13T00:00:01Z', $payload['event'][0]['period']['start']);
        $this->assertNull($payload['event'][0]['period']['end']);

        $byCode = collect($payload['extension'])->keyBy('valueCode');
        $this->assertSame('2026-08-13', $byCode['NEWBORN_BIRTH_DATE']['valueDate']);
        $this->assertSame('MALE', $byCode['NEWBORN_SEX']['valueString']);
    }

    #[Test]
    public function a_birth_conclusion_is_filed_against_the_newborn_with_the_mother_as_focus(): void
    {
        $payload = $this->mapper()->newborn([
            'category' => 'LIVE_BIRTH',
            'prepersonUuid' => 'c7c41d7e-f0e5-4118-b5be-fedfb5a1e8ed',
            'encounterUuid' => self::ENCOUNTER,
            'personUuid' => self::SUBJECT,
            'newbornBirthDate' => '2026-08-13',
            'newbornSex' => 'FEMALE',
        ], self::AUTHOR);

        $this->assertSame('preperson', $payload['subject']['type']['coding'][0]['code']);
        $this->assertSame('person', $payload['section']['focus']['type']['coding'][0]['code']);
        $this->assertSame(self::SUBJECT, $payload['section']['focus']['value']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function tempDisability(array $overrides = []): array
    {
        return $this->mapper()->tempDisability(array_merge([
            'category' => 'SICKNESS',
            'subjectUuid' => self::SUBJECT,
            'isUnidentified' => false,
            'encounterUuid' => self::ENCOUNTER,
            'sectionFocusUuid' => self::SUBJECT,
            'eventPeriodStart' => '2026-08-01',
            'eventPeriodEnd' => '2026-08-10',
        ], $overrides), self::AUTHOR);
    }

    private function mapper(): CompositionMapper
    {
        return new CompositionMapper();
    }
}
