#!/usr/bin/env bash
# Run PHPUnit suites owned for:
# Employee · Party · Care Plan · Care Plan Activity · ePrescription · Service Request · Device Request
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

mkdir -p storage/ci

php artisan config:clear --no-interaction

# Paths kept explicit so CI can expand gradually without running the full suite.
DOMAIN_PATHS=(
  tests/Unit/Employee
  tests/Unit/Config/PartyVerificationScopesTest.php
  tests/Unit/Config/PrepersonPolicyConfigurationTest.php
  tests/Unit/Enums/Party
  tests/Unit/Enums/CarePlanStatusTest.php
  tests/Unit/Livewire/Employee
  tests/Unit/Livewire/Party
  tests/Unit/Livewire/CarePlan
  tests/Unit/Livewire/CarePlanLockedStateTest.php
  tests/Unit/CarePlan
  tests/Unit/Exceptions/EHealthValidationExceptionCarePlanMessageTest.php
  tests/Unit/Helpers/ClinicalTimezoneConversionTest.php
  tests/Unit/Models/CarePlanActivityResolvedKindTest.php
  tests/Unit/Repositories/CarePlanActivityRepositoryTest.php
  tests/Unit/Repositories/RepositoryCarePlanTest.php
  tests/Unit/Repositories/MedicalEvents/MedicationRequestRepositoryFhirRefsTest.php
  tests/Unit/Services/Party
  tests/Unit/Services/MedicalEvents/CarePlanActivityLifecycleServiceTest.php
  tests/Unit/Services/MedicalEvents/CarePlanLifecycleServiceTest.php
  tests/Unit/Services/MedicalEvents/MedicationRequestSignPayloadTest.php
  tests/Unit/Services/MedicalEvents/Mappers/CarePlanMapperTest.php
  tests/Unit/Services/MedicalEvents/Mappers/CarePlanActivityMapperTest.php
  tests/Unit/Services/MedicalEvents/Mappers/MedicationRequestMapperTest.php
  tests/Unit/Services/MedicalEvents/ServiceRequestMapperTest.php
  tests/Unit/Services/MedicalEvents/ServiceRequestFhirRoundTripTest.php
  tests/Unit/Services/MedicalEvents/ResolvesEmployeeContextTest.php
  tests/Unit/Services/MedicalEvents/ReferralSignPayloadTest.php
  tests/Unit/Services/MedicalEvents/ReferralRequestLifecycleWriteTest.php
  tests/Unit/Services/MedicalEvents/ReferralPrintoutCode128Test.php
  tests/Unit/Services/MedicalEvents/DeviceRequestMapperTest.php
  tests/Unit/Services/MedicalEvents/DeviceRequestPrescribingTest.php
  tests/Unit/Services/MedicalEvents/EncounterReferralDisplayTest.php
  tests/Unit/eHealth/Api/Patient/MedicationRequestRejectPayloadTest.php
  tests/Unit/eHealth/Api/ConfigurationQueryTest.php
  tests/Feature/Employee
  tests/Feature/Party
  tests/Feature/CarePlan
  tests/Feature/CarePlanActivityPayloadTest.php
  tests/Feature/Encounter/EncounterDocumentPagesTest.php
  tests/Feature/Encounter/EncounterTransferDivisionLookupTest.php
  tests/Feature/Livewire/CarePlan
  tests/Feature/MedicationRequest
  tests/Feature/DeviceRequest
  tests/Feature/MedicationRequestTest.php
  tests/Feature/Referral
  tests/Feature/ReferralTest.php
  tests/Feature/Api/ReferralControllerTest.php
  tests/Feature/Api/ReferralControllerProcessCancelUsageTest.php
  tests/Feature/EHealth/EHealthResponseExceptionPartyNotVerifiedTest.php
  tests/Feature/Models/UserGetCarePlanWriterEmployeeTest.php
  tests/Feature/Person/PatientMedicationRequestsPhase6Test.php
  tests/Feature/Person/PatientReferralsPhase6Test.php
  tests/Feature/Services/MedicalEvents/MedicationRequestLifecycleServiceTest.php
  tests/Feature/Services/MedicalEvents/MedicationRequestFhirStorageTest.php
)

# Composition is a later module in the review stack; run its regressions once that slice is present.
if [[ -f tests/Feature/Composition/CompositionFhirFidelityTest.php ]]; then
  DOMAIN_PATHS+=(
    tests/Feature/Composition/CompositionFhirFidelityTest.php
    tests/Feature/Composition/CompositionReadRelationsTest.php
    tests/Feature/Composition/CompositionTempDisabilityCreateTest.php
    tests/Feature/Person/PatientRegistryUatTest.php
  )
fi

echo "==> Running domain PHPUnit paths (${#DOMAIN_PATHS[@]} entries)"
./vendor/bin/phpunit \
  --configuration=phpunit.xml \
  --log-junit=storage/ci/phpunit-domain-junit.xml \
  "${DOMAIN_PATHS[@]}"
