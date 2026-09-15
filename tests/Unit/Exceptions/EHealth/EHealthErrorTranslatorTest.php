<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions\EHealth;

use App\Exceptions\EHealth\EHealthErrorTranslator;
use Tests\TestCase;

class EHealthErrorTranslatorTest extends TestCase
{
    public function test_translates_rule_1172_by_code_prefix(): void
    {
        $this->assertSame(
            __('errors.ehealth.rules.1172'),
            EHealthErrorTranslator::translate(
                '1172: Treatment violation date should be >= composition start && <= now'
            )
        );
    }

    public function test_translates_treatment_violation_date_english_without_code(): void
    {
        $this->assertSame(
            __('errors.ehealth.messages.treatment_violation_date_range'),
            EHealthErrorTranslator::translate(
                'Treatment violation date should be >= composition start && <= now'
            )
        );
    }

    public function test_translates_rule_1143_by_code_prefix(): void
    {
        $this->assertSame(
            __('errors.ehealth.rules.1143'),
            EHealthErrorTranslator::translate(
                '1143: Existing composition has start date after suggested start date'
            )
        );
    }

    public function test_leaves_unknown_messages_unchanged(): void
    {
        $this->assertSame(
            'Some obscure upstream failure',
            EHealthErrorTranslator::translate('Some obscure upstream failure')
        );
    }
}
