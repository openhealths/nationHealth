<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions\EHealth;

use App\Exceptions\EHealth\EHealthException;
use Tests\TestCase;

class EHealthExceptionTranslateTest extends TestCase
{
    public function test_translates_rule_1172_by_code_prefix(): void
    {
        $this->assertSame(
            __('errors.ehealth.rules.1172'),
            EHealthException::translate(
                '1172: Treatment violation date should be >= composition start && <= now'
            )
        );
    }

    public function test_translates_treatment_violation_date_english_without_code(): void
    {
        $this->assertSame(
            __('errors.ehealth.messages.treatment_violation_date_range'),
            EHealthException::translate(
                'Treatment violation date should be >= composition start && <= now'
            )
        );
    }

    public function test_translates_rule_1143_by_code_prefix(): void
    {
        $this->assertSame(
            __('errors.ehealth.rules.1143'),
            EHealthException::translate(
                '1143: Existing composition has start date after suggested start date'
            )
        );
    }

    public function test_translates_rule_1167_by_code_prefix(): void
    {
        $this->assertSame(
            __('errors.ehealth.rules.1167'),
            EHealthException::translate('1167: Illegal author position')
        );
    }

    public function test_translates_illegal_author_position_english_without_code(): void
    {
        $this->assertSame(
            __('errors.ehealth.messages.illegal_author_position'),
            EHealthException::translate('Illegal author position')
        );
    }

    public function test_leaves_unknown_messages_unchanged(): void
    {
        $this->assertSame(
            'Some obscure upstream failure',
            EHealthException::translate('Some obscure upstream failure')
        );
    }
}
