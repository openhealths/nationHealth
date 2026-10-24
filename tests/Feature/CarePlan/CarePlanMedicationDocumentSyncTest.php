<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\MedicationRequestRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CarePlanMedicationDocumentSyncTest extends TestCase
{
    use DatabaseTransactions;

    private function request(): MedicationRequestRequest
    {
        $person = Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => '1990-01-01', 'gender' => 'MALE']);
        $basis = Identifier::create(['value' => (string) Str::uuid()]);

        return MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id,
            'based_on_id' => $basis->id, 'status' => 'new',
            'request_number' => 'synthetic-request-number', 'medication_qty' => 1.25,
        ]);
    }

    public function test_sync_keeps_separate_signed_request_and_active_prescription_identities(): void
    {
        $request = $this->request();
        $prescriptionUuid = (string) Str::uuid();
        $repository = app(MedicationRequestRepository::class);
        $repository->synchronizeCarePlanDocuments(collect([$request]), [
            ['id' => $prescriptionUuid, 'request_number' => $request->requestNumber, 'status' => 'ACTIVE'],
        ], [['id' => $request->uuid, 'status' => 'NEW']]);

        $this->assertSame('signed', $request->fresh()->status);
        $this->assertSame($prescriptionUuid, $request->fresh()->ehealthPayload['active_id']);
        $prescription = MedicationRequestRequest::whereUuid($prescriptionUuid)->firstOrFail();
        $this->assertSame(MedicationRequestRequest::TYPE_PRESCRIPTION, $prescription->resourceType);
        $this->assertSame('active', strtolower($prescription->status));
        $this->assertNotSame($request->basedOnId, $prescription->basedOnId);
        $this->assertSame($request->basedOn->value, $prescription->basedOn->value);
        $this->assertSame(1.25, $repository->issuedCarePlanQuantity($request->basedOn->value));
        $identifierCount = Identifier::count();
        $this->assertSame(0, $repository->synchronizeCarePlanDocuments(collect([$request->fresh(), $prescription->fresh()]), [
            ['id' => $prescriptionUuid, 'request_number' => $request->requestNumber, 'status' => 'ACTIVE'],
        ], [['id' => $request->uuid, 'status' => 'NEW']]));
        $this->assertSame($identifierCount, Identifier::count());
        $this->assertSame(2, MedicationRequestRequest::wherePersonId($request->personId)->count());

    }

    public function test_linked_prescription_counts_once_and_its_expiry_releases_quantity(): void
    {
        $request = $this->request();
        $repository = app(MedicationRequestRepository::class);
        $active = ['id' => (string) Str::uuid(), 'request_number' => $request->requestNumber, 'status' => 'ACTIVE'];
        $repository->synchronizeCarePlanDocuments(collect([$request]), [$active], []);
        $external = $request->replicate();
        $external->fill(['uuid' => (string) Str::uuid(), 'resource_type' => MedicationRequestRequest::TYPE_PRESCRIPTION,
            'source' => MedicationRequestRequest::SOURCE_EHEALTH, 'status' => 'active', 'medication_qty' => 0.5])->save();
        $this->assertSame(1.75, $repository->issuedCarePlanQuantity($request->basedOn->value));

        $active['status'] = 'EXPIRED';
        $repository->synchronizeCarePlanDocuments(collect([$request->fresh()]), [$active], []);
        $this->assertSame('signed', $request->fresh()->status);
        $this->assertSame(0.5, $repository->issuedCarePlanQuantity($request->basedOn->value));
    }

    public function test_ambiguous_request_number_cannot_link_another_prescription(): void
    {
        $request = $this->request();
        $other = $request->replicate();
        $other->uuid = (string) Str::uuid();
        $other->save();
        app(MedicationRequestRepository::class)->synchronizeCarePlanDocuments(collect([$request, $other]), [
            ['id' => (string) Str::uuid(), 'request_number' => $request->requestNumber, 'status' => 'ACTIVE'],
        ], []);

        $this->assertSame('new', $request->fresh()->status);
        $this->assertSame('new', $other->fresh()->status);
    }

    public function test_patient_mismatch_rolls_back_the_sync(): void
    {
        $request = $this->request();
        try {
            app(MedicationRequestRepository::class)->synchronizeCarePlanDocuments(collect([$request]), [
                ['id' => (string) Str::uuid(), 'request_number' => $request->requestNumber, 'status' => 'ACTIVE',
                    'person' => ['id' => (string) Str::uuid()]],
            ], []);
            $this->fail('Patient mismatch must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame('new', $request->fresh()->status);
            $this->assertSame(1, MedicationRequestRequest::wherePersonId($request->personId)->count());
        }
    }

    public function test_entered_in_error_api_spelling_releases_quantity_without_retagging_the_request(): void
    {
        $request = $this->request();
        $repository = app(MedicationRequestRepository::class);
        $active = ['id' => (string) Str::uuid(), 'request_number' => $request->requestNumber, 'status' => 'ACTIVE'];
        $repository->synchronizeCarePlanDocuments(collect([$request]), [$active], []);
        $active['status'] = 'ENTERED_IN_ERROR';
        $repository->synchronizeCarePlanDocuments(collect([$request->fresh()]), [$active], []);
        $this->assertSame('signed', $request->fresh()->status);
        $this->assertSame('entered-in-error', MedicationRequestRequest::whereUuid($active['id'])->firstOrFail()->status);
        $this->assertSame(0.0, $repository->issuedCarePlanQuantity($request->basedOn->value));
    }
}
