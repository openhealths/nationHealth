<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Tests\TestCase;

class ContractIdFormLabelTest extends TestCase
{
    public function test_returns_null_for_empty_code(): void
    {
        $this->assertNull(contractIdFormLabel(null, 'REIMBURSEMENT'));
        $this->assertNull(contractIdFormLabel('', 'REIMBURSEMENT'));
    }

    public function test_translates_general_reimbursement_id_form(): void
    {
        $this->assertSame(
            'Загальний реімбурсаційний договір',
            contractIdFormLabel('GENERAL', 'REIMBURSEMENT')
        );
    }

    public function test_translates_capitation_pmd_1_id_form(): void
    {
        $this->assertSame(
            'Договір про медичне обслуговування населення за програмою медичних гарантій',
            contractIdFormLabel('PMD_1', 'CAPITATION')
        );
    }

    public function test_falls_back_to_code_when_translation_missing(): void
    {
        $this->assertSame('UNKNOWN_CODE', contractIdFormLabel('UNKNOWN_CODE', 'REIMBURSEMENT'));
    }
}
