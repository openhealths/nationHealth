<?php

namespace Tests\Unit\Pharmacy;

use PHPUnit\Framework\TestCase;
use App\Services\Pharmacy\Domain\Dictionaries\PharmacyDictionaries;
use App\Services\Pharmacy\Domain\Services\PharmacyCalculationEngine;
use App\Services\Pharmacy\Domain\Services\PharmacyValidationService;
use DomainException;
use InvalidArgumentException;

class PharmacyModuleTest extends TestCase
{
    private PharmacyCalculationEngine $calcEngine;
    private PharmacyValidationService $validationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calcEngine = new PharmacyCalculationEngine();
        $this->validationService = new PharmacyValidationService();
    }

    public function testMedicationRequestStatusValidations(): void
    {
        $myLegalEntityId = 'le-123';
        $otherLegalEntityId = 'le-999';

        // COMPLETED
        $resCompleted = $this->validationService->validateMedicationRequestStatus(['status' => 'COMPLETED'], $myLegalEntityId);
        $this->assertFalse($resCompleted['can_dispense']);

        // EXPIRED
        $resExpired = $this->validationService->validateMedicationRequestStatus(['status' => 'EXPIRED'], $myLegalEntityId);
        $this->assertFalse($resExpired['can_dispense']);

        // REJECTED
        $resRejected = $this->validationService->validateMedicationRequestStatus([
            'status' => 'REJECTED',
            'reject_reason_code' => 'OUT_OF_STOCK',
        ], $myLegalEntityId);
        $this->assertFalse($resRejected['can_dispense']);
        $this->assertEquals('OUT_OF_STOCK', $resRejected['details']['reject_reason_code']);

        // BLOCKED by other
        $resBlockedOther = $this->validationService->validateMedicationRequestStatus([
            'status' => 'ACTIVE',
            'blocked_to' => '2099-01-01T12:00:00Z',
            'blocked_by_legal_entity' => ['id' => $otherLegalEntityId],
        ], $myLegalEntityId);
        $this->assertFalse($resBlockedOther['can_dispense']);

        // BLOCKED by self
        $resBlockedOwn = $this->validationService->validateMedicationRequestStatus([
            'status' => 'ACTIVE',
            'blocked_to' => '2099-01-01T12:00:00Z',
            'blocked_by_legal_entity' => ['id' => $myLegalEntityId],
        ], $myLegalEntityId);
        $this->assertTrue($resBlockedOwn['can_dispense']);
    }

    public function testErrorTranslationAndSingleRegistryRule(): void
    {
        $err1 = $this->validationService->translateQualificationError("Error: Division does not provide the medical program");
        $this->assertEquals(PharmacyDictionaries::ERROR_MESSAGES['DIVISION_NOT_PROVIDE_PROGRAM'], $err1);

        $err2 = $this->validationService->translateQualificationError("Error: Medical program provision is not related to any actual contract for the current date");
        $this->assertEquals(PharmacyDictionaries::ERROR_MESSAGES['PROGRAM_PROVISION_NOT_RELATED_CONTRACT'], $err2);

        $singleReg = [
            ['medication_name' => 'ЛЗ 1', 'registry_number' => 'REG-1'],
            ['medication_name' => 'ЛЗ 2', 'registry_number' => 'REG-1'],
        ];
        $this->assertEquals('REG-1', $this->validationService->validateSingleRegistrySelection($singleReg));

        $this->expectException(DomainException::class);
        $this->validationService->validateSingleRegistrySelection([
            ['medication_name' => 'ЛЗ 1', 'registry_number' => 'REG-1'],
            ['medication_name' => 'ЛЗ 2', 'registry_number' => 'REG-2'],
        ]);
    }

    public function testCalculationAndPriceAdjustments(): void
    {
        $calc = $this->calcEngine->calculateMedicationNhsAmounts(100.0, 70.0, 2);
        $this->assertEquals(200.00, $calc['sell_amount']);
        $this->assertEquals(140.00, $calc['discount_amount']);
        $this->assertEquals(60.00, $calc['estimated_payment_amount']);

        $this->expectException(InvalidArgumentException::class);
        $this->calcEngine->validatePriceAdjustment(150.00, 140.00, 'discount_amount');
    }

    public function testDeviceDiscountFormulaAndCalculations(): void
    {
        $deviceCalc = $this->calcEngine->calculateDeviceDiscountAmount(250.00, 100.0, 50, 300.00);
        $this->assertEquals(2.0, $deviceCalc['packages_count']);
        $this->assertEquals(600.00, $deviceCalc['sell_amount']);
        $this->assertEquals(500.00, $deviceCalc['discount_amount']);
        $this->assertEquals(100.00, $deviceCalc['estimated_payment_amount']);
    }
}
