<?php

declare(strict_types=1);

namespace Tests\Unit\Enums\Contract;

use App\Enums\Contract\ContractStatus;
use App\Enums\Contract\IdForm;
use App\Enums\Contract\PaymentMethod;
use App\Enums\Contract\ContractRequestStatus;
use App\Enums\Contract\Type;
use Tests\TestCase;

class ContractLabelEnumsTest extends TestCase
{
    public function test_id_form_resolve_label_returns_null_for_empty_code(): void
    {
        $this->assertNull(IdForm::resolveLabel(null, Type::REIMBURSEMENT));
        $this->assertNull(IdForm::resolveLabel('', Type::REIMBURSEMENT));
    }

    public function test_id_form_general_has_reimbursement_label(): void
    {
        $this->assertSame(
            'Загальний реімбурсаційний договір',
            IdForm::resolveLabel(IdForm::GENERAL->value, Type::REIMBURSEMENT)
        );
        $this->assertSame(
            'Загальний реімбурсаційний договір',
            IdForm::GENERAL->label(Type::REIMBURSEMENT)
        );
    }

    public function test_id_form_pmd_1_depends_on_contract_type(): void
    {
        $this->assertSame(
            'Доступні ліки',
            IdForm::resolveLabel(IdForm::PMD_1->value, Type::REIMBURSEMENT)
        );
        $this->assertSame(
            'Договір про медичне обслуговування населення за програмою медичних гарантій',
            IdForm::resolveLabel(IdForm::PMD_1->value, Type::CAPITATION)
        );
    }

    public function test_id_form_falls_back_to_code_when_unknown(): void
    {
        $this->assertSame('UNKNOWN_CODE', IdForm::resolveLabel('UNKNOWN_CODE', Type::REIMBURSEMENT));
    }

    public function test_type_resolve_label(): void
    {
        $this->assertSame('Реімбурсація', Type::resolveLabel(Type::REIMBURSEMENT));
        $this->assertSame('Капітація', Type::resolveLabel('CAPITATION'));
    }

    public function test_payment_method_resolve_label(): void
    {
        $this->assertSame('Попередня оплата', PaymentMethod::resolveLabel('FORWARD'));
        $this->assertSame('Післяплата', PaymentMethod::resolveLabel(PaymentMethod::BACKWARD));
        $this->assertSame('-', PaymentMethod::resolveLabel(null));
    }

    public function test_contract_request_signed_is_completed_not_signed(): void
    {
        $this->assertSame('Завершена', ContractRequestStatus::SIGNED->label());
        $this->assertSame('Завершена', ContractRequestStatus::resolveLabel('SIGNED'));
        $this->assertStringNotContainsStringIgnoringCase('підпис', ContractRequestStatus::SIGNED->label());
    }

    public function test_contract_terminated_is_terminated_not_completed(): void
    {
        $this->assertSame('Розірваний', ContractStatus::TERMINATED->label());
        $this->assertSame('Розірваний', ContractStatus::resolveLabel('TERMINATED'));
        $this->assertSame('Діючий', ContractStatus::VERIFIED->label());
        $this->assertSame('Діючий', ContractStatus::ACTIVE->label());
        $this->assertSame('Припинена', ContractRequestStatus::TERMINATED->label());
    }

    public function test_contract_status_filter_options_are_verified_and_terminated(): void
    {
        $options = ContractStatus::listFilterOptions();

        $this->assertSame(['VERIFIED', 'TERMINATED'], array_keys($options));
        $this->assertSame(
            ['VERIFIED', 'ACTIVE'],
            ContractStatus::expandFilterValues(['VERIFIED'])
        );
        $this->assertNull(ContractStatus::tryFrom('SIGNED'));
        $this->assertSame(ContractRequestStatus::SIGNED, ContractRequestStatus::tryFrom('SIGNED'));
    }
}
