<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Concerns;

use App\Classes\eHealth\EHealth;
use App\Enums\MedicalProgram\Type as MedicalProgramType;
use App\Enums\Person\EncounterStatus;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait ManagesEncounterEPrescription
{
    // Uses ResolvesEncounterStandaloneContext via EncounterEdit.

    public bool $showEncounterEPrescriptionDrawer = false;

    /** @var array<string, mixed> */
    public array $encounterEPrescriptionForm = [];

    /** @var list<array{uuid: string, type: string, label: string, value: string}> */
    public array $encounterEPrescriptionAuthMethods = [];

    #[Locked]
    public ?string $encounterEPrescriptionRequestIdToSign = null;

    public string $encounterEPrescriptionWarningMessage = '';

    /** @var list<array{id: string, name: string}> */
    public array $encounterEPrescriptionPrograms = [];

    public string $encounterEPrescriptionSearchQuery = '';

    /** @var list<array<string, mixed>> */
    public array $encounterEPrescriptionSearchResults = [];

    /** @var array<string, mixed>|null */
    public ?array $encounterEPrescriptionSelectedMedication = null;

    public function openEncounterEPrescriptionDrawer(): void
    {
        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null) {
            return;
        }

        $status = $encounter->status instanceof EncounterStatus
            ? $encounter->status
            : EncounterStatus::tryFrom((string) $encounter->status);

        if ($status !== EncounterStatus::FINISHED) {
            Session::flash('error', 'Електронний рецепт без плану лікування можна створити лише після завершення взаємодії.');

            return;
        }

        $this->loadEncounterEPrescriptionAuthMethods($encounter);
        $this->loadEncounterEPrescriptionPrograms();

        $this->encounterEPrescriptionForm = [
            'medication_id' => '',
            'program_id' => '',
            'category' => 'community',
            'medication_qty' => '1',
            'medication_unit' => 'од.',
            'signature_text' => '',
            'patient_instruction' => '',
            'route' => 'oral',
            'max_dose_per_administration' => '1',
            'max_dose_per_period' => '1',
            'started_at' => now()->toDateString(),
            'ended_at' => now()->addDays(30)->toDateString(),
            'note' => '',
            'inform_with' => $this->encounterEPrescriptionAuthMethods[0]['value'] ?? '',
        ];

        $this->encounterEPrescriptionSearchQuery = '';
        $this->encounterEPrescriptionSearchResults = [];
        $this->encounterEPrescriptionSelectedMedication = null;
        $this->encounterEPrescriptionWarningMessage = '';
        $this->showEncounterEPrescriptionDrawer = true;
    }

    public function closeEncounterEPrescriptionDrawer(): void
    {
        $this->showEncounterEPrescriptionDrawer = false;
        $this->encounterEPrescriptionWarningMessage = '';
    }

    public function searchEncounterEPrescriptionMedications(): void
    {
        $selectedProgramId = (string) ($this->encounterEPrescriptionForm['program_id'] ?? '');
        if ($selectedProgramId === '') {
            $this->encounterEPrescriptionSearchResults = [];
            $this->encounterEPrescriptionWarningMessage = 'Спочатку оберіть медичну програму.';

            return;
        }

        $query = trim($this->encounterEPrescriptionSearchQuery);
        if (mb_strlen($query) < 3) {
            $this->encounterEPrescriptionSearchResults = [];
            $this->encounterEPrescriptionWarningMessage = 'Введіть щонайменше 3 символи для пошуку лікарського засобу.';

            return;
        }

        $filters = [
            'innm_name' => $query,
            'page' => 1,
            'page_size' => 20,
        ];

        $filters['medical_program_id'] = $selectedProgramId;

        try {
            $response = EHealth::drug()->getMany($filters)->getData();
            $this->encounterEPrescriptionSearchResults = collect(is_array($response) ? $response : [])
                ->filter(function (array $drug) use ($query): bool {
                    $name = mb_strtolower((string) ($drug['name'] ?? ''));
                    $innmName = mb_strtolower((string) ($drug['innm_name'] ?? ''));
                    $needle = mb_strtolower($query);

                    return str_contains($name, $needle) || str_contains($innmName, $needle);
                })
                ->map(static function (array $drug): array {
                    return [
                        'id' => (string) ($drug['id'] ?? ''),
                        'name' => (string) ($drug['name'] ?? ''),
                        'innm_name' => (string) ($drug['innm_name'] ?? ''),
                        'innm_dosage_form' => (string) ($drug['innm_dosage_form'] ?? ''),
                        'packages' => is_array($drug['packages'] ?? null) ? $drug['packages'] : [],
                    ];
                })
                ->filter(static fn (array $drug): bool => $drug['id'] !== '')
                ->values()
                ->all();
            $this->encounterEPrescriptionWarningMessage = '';
        } catch (\Throwable $exception) {
            Log::error('EncounterEdit: medication search failed for standalone eRx: '.$exception->getMessage());
            $this->encounterEPrescriptionSearchResults = [];
            $this->encounterEPrescriptionWarningMessage = 'Не вдалося виконати пошук лікарських засобів. Спробуйте ще раз.';
        }
    }

    public function selectEncounterEPrescriptionMedication(string $medicationId): void
    {
        $selectedMedication = collect($this->encounterEPrescriptionSearchResults)
            ->first(static fn (array $drug): bool => (string) ($drug['id'] ?? '') === $medicationId);

        if (!is_array($selectedMedication)) {
            $this->encounterEPrescriptionWarningMessage = 'Не вдалося обрати лікарський засіб. Спробуйте пошукати ще раз.';

            return;
        }

        $this->encounterEPrescriptionForm['medication_id'] = $medicationId;
        $this->encounterEPrescriptionSelectedMedication = $selectedMedication;
        if (($selectedMedication['innm_dosage_form'] ?? '') !== '') {
            $this->encounterEPrescriptionForm['medication_unit'] = (string) $selectedMedication['innm_dosage_form'];
        }

        $packageStep = $this->resolveEncounterMedicationPackageStep($selectedMedication);
        if ($packageStep > 0) {
            $this->encounterEPrescriptionForm['medication_qty'] = (string) $packageStep;
        }

        $this->encounterEPrescriptionWarningMessage = '';
    }

    public function updatedEncounterEPrescriptionFormProgramId(): void
    {
        $this->encounterEPrescriptionForm['medication_id'] = '';
        $this->encounterEPrescriptionSelectedMedication = null;
        $this->encounterEPrescriptionSearchResults = [];
    }

    public function validateEncounterEPrescription(): void
    {
        $this->encounterEPrescriptionWarningMessage = '';

        $this->validate([
            'encounterEPrescriptionForm.medication_id' => 'required|string|uuid',
            'encounterEPrescriptionForm.program_id' => 'required|string|uuid',
            'encounterEPrescriptionForm.category' => 'required|string',
            'encounterEPrescriptionForm.medication_qty' => 'required|numeric|min:0.01',
            'encounterEPrescriptionForm.signature_text' => 'required|string|min:1',
            'encounterEPrescriptionForm.max_dose_per_administration' => 'required|numeric|min:0.01',
            'encounterEPrescriptionForm.max_dose_per_period' => 'required|numeric|min:0.01',
            'encounterEPrescriptionForm.started_at' => 'required|date',
            'encounterEPrescriptionForm.ended_at' => 'required|date|after_or_equal:encounterEPrescriptionForm.started_at',
            'encounterEPrescriptionForm.inform_with' => 'required|string',
        ], [], [
            'encounterEPrescriptionForm.medication_id' => 'ідентифікатор ЛЗ',
            'encounterEPrescriptionForm.program_id' => 'медична програма',
            'encounterEPrescriptionForm.category' => 'категорія',
            'encounterEPrescriptionForm.medication_qty' => 'кількість',
            'encounterEPrescriptionForm.signature_text' => 'сигнатура',
            'encounterEPrescriptionForm.inform_with' => 'метод автентифікації',
        ]);

        $medicationQty = (float) ($this->encounterEPrescriptionForm['medication_qty'] ?? 0);
        $packageStep = $this->resolveEncounterMedicationPackageStep($this->encounterEPrescriptionSelectedMedication);
        if ($packageStep > 0 && !$this->isEncounterMedicationQtyDivisible($medicationQty, $packageStep)) {
            $this->encounterEPrescriptionWarningMessage = "Кількість ЛЗ має бути кратною фасуванню ({$packageStep}).";
            Session::flash('error', $this->encounterEPrescriptionWarningMessage);

            return;
        }

        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null) {
            return;
        }

        try {
            $employeeContext = app(MedicationRequestLifecycleService::class)->resolveEncounterEmployeeContext(
                $encounter,
                Auth::user()?->activeDoctorEmployee()?->id
            );

            $formData = $this->encounterEPrescriptionForm;

            $this->encounterEPrescriptionRequestIdToSign = app(MedicationRequestLifecycleService::class)
                ->createEncounterDraft($encounter, $formData, $employeeContext);

            $this->actionType = 'sign_eprescription';
            $this->showSignatureModal = true;
            $infoMessage = 'Заявку на е-рецепт створено. Підпишіть КЕП.';
            Session::flash('success', $infoMessage);
        } catch (EHealthValidationException $exception) {
            $exception->report();
            $this->encounterEPrescriptionWarningMessage = $exception->getTranslatedMessage();
            Session::flash('error', $this->encounterEPrescriptionWarningMessage);
        } catch (\Throwable $exception) {
            Log::error('EncounterEdit: failed to create encounter eRx: '.$exception->getMessage());
            $this->encounterEPrescriptionWarningMessage = 'Не вдалося створити заявку на рецепт: '.$exception->getMessage();
            Session::flash('error', $this->encounterEPrescriptionWarningMessage);
        }
    }

    public function signEncounterEPrescription(): void
    {
        if (empty($this->encounterEPrescriptionRequestIdToSign)) {
            Session::flash('error', 'Не вибрано рецепт для підписання');
            $this->showSignatureModal = false;
            $this->actionType = null;

            return;
        }

        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null) {
            $this->showSignatureModal = false;
            $this->actionType = null;

            return;
        }

        $requestRecord = app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->medicationForEncounter(
                (string) $this->encounterEPrescriptionRequestIdToSign,
                $encounter
            );

        try {
            $validated = $this->form->validate($this->form->signingRules());

            $informWith = (string) ($this->encounterEPrescriptionForm['inform_with'] ?? $requestRecord->informWith ?? '');
            if ($informWith !== '' && !str_contains($informWith, '|')) {
                $matchedAuth = collect($this->encounterEPrescriptionAuthMethods)
                    ->first(static fn (array $method): bool => (string) ($method['uuid'] ?? '') === $informWith);
                if (is_array($matchedAuth)) {
                    $informWith = (string) ($matchedAuth['value'] ?? $informWith);
                }
            }

            $result = app(MedicationRequestLifecycleService::class)->signPrescription(
                $encounter,
                $requestRecord,
                [
                    'password' => $validated['password'],
                    'knedp' => $validated['knedp'],
                    'keyContainerUpload' => $validated['keyContainerUpload'],
                    'medication_unit' => $this->encounterEPrescriptionForm['medication_unit'] ?? 'од.',
                    'signer_tax_id' => Auth::user()?->party?->taxId,
                ],
                $informWith,
                0.0
            );

            $this->showSignatureModal = false;
            $this->actionType = null;
            $this->encounterEPrescriptionRequestIdToSign = null;
            $this->form->resetSigningFields();

            $message = $result['success_message']
                ?? 'Електронний рецепт успішно створено без плану лікування.';
            Session::flash('success', $message);
            $this->showEncounterEPrescriptionDrawer = false;
            $this->encounterEPrescriptionForm = [];
            $this->encounterEPrescriptionSearchResults = [];
            $this->encounterEPrescriptionSelectedMedication = null;
        } catch (ValidationException $exception) {
            $message = $exception->validator->errors()->first() ?: 'Перевірте дані КЕП і спробуйте ще раз.';
            Session::flash('error', $message);
            $this->setErrorBag($exception->validator->getMessageBag());
        } catch (EHealthValidationException $exception) {
            $exception->report();
            $message = $exception->getTranslatedMessage();
            Session::flash('error', $message);
            $this->showSignatureModal = false;
            $this->actionType = null;
        } catch (\Throwable $exception) {
            Log::error('EncounterEdit: failed to sign encounter eRx: '.$exception->getMessage());
            $message = 'Не вдалося підписати рецепт: '.$exception->getMessage();
            Session::flash('error', $message);
            $this->showSignatureModal = false;
            $this->actionType = null;
        }
    }

    protected function loadEncounterEPrescriptionAuthMethods(Encounter $encounter): void
    {
        $this->encounterEPrescriptionAuthMethods = [];
        $person = Person::find($encounter->person_id);
        if ($person === null || empty($person->uuid)) {
            return;
        }

        try {
            $authMethods = EHealth::person()->getAuthMethods($person->uuid)->getData();
            if (!is_array($authMethods)) {
                return;
            }

            $this->encounterEPrescriptionAuthMethods = collect($authMethods)->map(static function (array $method): array {
                $uuid = (string) ($method['id'] ?? $method['uuid'] ?? '');
                $type = (string) ($method['type'] ?? '');
                $phone = (string) ($method['phone_number'] ?? $method['value'] ?? '');
                $value = $uuid;
                if ($uuid !== '') {
                    $value = "{$uuid}|{$type}|{$phone}";
                }

                return [
                    'uuid' => $uuid,
                    'type' => $type,
                    'label' => trim($type.($phone !== '' ? ' · '.$phone : '')),
                    'value' => $value,
                ];
            })->filter(static fn (array $m): bool => $m['uuid'] !== '')->values()->all();
        } catch (\Throwable $exception) {
            Log::warning('EncounterEdit: failed to load auth methods for eRx: '.$exception->getMessage());
        }
    }

    protected function loadEncounterEPrescriptionPrograms(): void
    {
        $this->encounterEPrescriptionPrograms = dictionary()->medicalPrograms()
            ->where('is_active', true)
            ->where('type', MedicalProgramType::MEDICATION->value)
            ->map(static fn (array $program): array => [
                'id' => (string) ($program['id'] ?? ''),
                'name' => (string) ($program['name'] ?? ''),
            ])
            ->filter(static fn (array $program): bool => $program['id'] !== '' && $program['name'] !== '')
            ->values()
            ->all();
    }

    protected function resolveEncounterMedicationPackageStep(?array $medication): float
    {
        if (!is_array($medication) || $medication === []) {
            return 0.0;
        }

        $packages = $medication['packages'] ?? [];
        if (!is_array($packages) || $packages === [] || !is_array($packages[0])) {
            return 0.0;
        }

        $minQty = (float) ($packages[0]['package_min_qty'] ?? 0);
        if ($minQty > 0) {
            return $minQty;
        }

        $packageQty = (float) ($packages[0]['package_qty'] ?? 0);

        return $packageQty > 0 ? $packageQty : 0.0;
    }

    protected function isEncounterMedicationQtyDivisible(float $quantity, float $step): bool
    {
        if ($step <= 0) {
            return true;
        }

        $quotient = $quantity / $step;

        return abs($quotient - round($quotient)) < 1e-6;
    }
}
