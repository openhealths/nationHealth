<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire;

use App\Livewire\CarePlan\CarePlanApprovals;
use App\Livewire\CarePlan\CarePlanComponent;
use App\Livewire\CarePlan\CarePlanShow;
use App\Traits\InteractsWithApprovals;
use Livewire\Attributes\Locked;
use ReflectionClass;
use Tests\TestCase;

/**
 * Livewire sends every public property to the browser and trusts what comes back, so anything
 * the server derived has to be locked. These are the properties an attacker would want to
 * change: identifiers pointing at a patient's records, and server-computed gates.
 */
class CarePlanLockedStateTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function serverOwnedProperties(): array
    {
        $properties = [
            CarePlanShow::class => [
                'carePlan',
                'carePlanUuid',
                'activityToSign',
                'activityToDelete',
                'dictionaries',
                'ePrescriptionRequestIdToSign',
                'ePrescriptionSelectedActivity',
                'ePrescriptionSelectedProgram',
                'ePrescriptionRemainingQty',
                'ePrescriptionEligibleEncounters',
                'referralRequestIdToSign',
                'referralSelectedActivity',
                'referralRemainingQty',
                'participatingDeviceProgramIds',
                'activePrescriptions',
                'activeReferrals',
                'printableContent',
                'searchResults',
                'selectedProduct',
                'availableConditions',
                'outcomeReferences',
                'approvalId',
                'pollingLinkId',
                'currentAuthMethod',
            ],
            CarePlanApprovals::class => [
                'carePlanId',
                'carePlanUuid',
                'patientUuid',
                'approvals',
                'employees',
                'approvalId',
                'pollingLinkId',
                'currentAuthMethod',
            ],
        ];

        $cases = [];

        foreach ($properties as $component => $names) {
            foreach ($names as $name) {
                $cases[class_basename($component).'::$'.$name] = [$component, $name];
            }
        }

        return $cases;
    }

    /**
     * @param  class-string  $component
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('serverOwnedProperties')]
    public function test_server_owned_properties_are_locked(string $component, string $property): void
    {
        $reflection = new ReflectionClass($component);

        $this->assertTrue(
            $reflection->hasProperty($property),
            $component.' no longer has $'.$property.'; update this test with its replacement'
        );

        $this->assertNotEmpty(
            $reflection->getProperty($property)->getAttributes(Locked::class),
            $component.'::$'.$property.' is sent to the browser unlocked'
        );
    }

    /**
     * Properties the user types into must stay writable, otherwise Livewire rejects the request.
     */
    public function test_form_bound_properties_stay_writable(): void
    {
        $writable = [
            CarePlanComponent::class => [
                'form',
                'activityForm',
                'ePrescriptionForm',
                'referralForm',
                'searchQuery',
                'selectedProgram',
                'statusReason',
                'outcomeCode',
                'selectedOutcomeReference',
                'showSignatureModal',
                'authMethods',
            ],
            CarePlanApprovals::class => [
                'newApproval',
                'selectedAuthMethodUuid',
                'verificationCode',
            ],
        ];

        foreach ($writable as $component => $names) {
            $reflection = new ReflectionClass($component);

            foreach ($names as $name) {
                $this->assertEmpty(
                    $reflection->getProperty($name)->getAttributes(Locked::class),
                    $component.'::$'.$name.' is bound in a view and cannot be locked'
                );
            }
        }
    }

    /**
     * Alpine entangles these, and entangling a locked property throws at runtime.
     */
    public function test_alpine_entangled_properties_are_not_locked(): void
    {
        $this->assertEmpty(
            (new ReflectionClass(CarePlanComponent::class))->getProperty('authMethods')->getAttributes(Locked::class)
        );
        $this->assertEmpty(
            (new ReflectionClass(InteractsWithApprovals::class))->getProperty('smsResent')->getAttributes(Locked::class)
        );
    }
}
