<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use DOMDocument;
use DOMXPath;
use Tests\Fixtures\CarePlan\ActivityPreview;
use Tests\TestCase;

class CarePlanActivityPreviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        view()->addNamespace('care-plan-fixtures', base_path('tests/Fixtures/CarePlan'));
    }

    public function test_card_displays_the_linked_referral_balance_or_a_placeholder(): void
    {
        foreach ([2, 0, null] as $remaining) {
            $row = ActivityPreview::cards()['service_request'];
            if ($remaining === null) {
                unset($row['activeReferrals'][0]['remaining_quantity']);
            } else {
                $row['activeReferrals'][0]['remaining_quantity'] = $remaining;
            }
            $html = view('livewire.care-plan.parts.activity.detail-card', $row)->render();
            $xpath = $this->xpath($html);
            $label = __('care-plan.created_available');
            $this->assertSame('3/'.($remaining ?? '-'), trim($xpath->evaluate(
                'string(//dl[contains(@class, "care-plan-card-document")]//dt[text()="'.$label.'"]/following-sibling::dd[1])'
            )));
            $this->assertStringContainsString('8', $xpath->evaluate('string(//div[@class="care-plan-activity-card-header"]/div[1])'));
        }
    }

    public function test_preview_renders_three_cards_and_service_choices_without_clinical_actions(): void
    {
        $html = view('care-plan-fixtures::activity-preview', ['cards' => ActivityPreview::cards(), 'services' => ActivityPreview::services()])->render();
        $xpath = $this->xpath($html);
        $this->assertSame(3, $xpath->query('//article')->length);
        $this->assertGreaterThan(1, $xpath->query('//input[@name="fixture-service"]')->length);
        $this->assertStringContainsString('5 мг/', $html);
        $this->assertStringNotContainsString('wire:click', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function test_service_daily_quantity_uses_its_own_unit(): void
    {
        foreach ([['PIECE', 'MINUTE', 'хв'], ['MINUTE', 'PIECE', 'шт']] as [$quantityCode, $dailyCode, $dailyUnit]) {
            $row = ActivityPreview::cards()['service_request'];
            $row['activity']->forceFill(['quantity' => 10, 'quantity_code' => $quantityCode, 'daily_amount' => 30, 'daily_amount_code' => $dailyCode]);
            $row['dictionaries']['SERVICE_UNIT'] = ['PIECE' => 'шт', 'MINUTE' => 'хв'];
            $html = view('livewire.care-plan.parts.activity.detail-card', $row)->render();
            $label = __('care-plan.daily_quantity_and_unit');
            $dailyValue = $this->xpath($html)->evaluate('string(//dl[contains(@class,"care-plan-card-details")]//dt[text()="'.$label.'"]/following-sibling::dd[1])');
            $this->assertSame('30 '.$dailyUnit.'/'.__('care-plan.per_day_short'), trim($dailyValue));
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
