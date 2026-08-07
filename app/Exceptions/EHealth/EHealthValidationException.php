<?php

declare(strict_types=1);

namespace App\Exceptions\EHealth;

use App\Core\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class EHealthValidationException extends EHealthException
{
    public function __construct(public readonly array $details)
    {
        parent::__construct('eHealth API returned a validation error.');
    }

    /**
     * Report the exception.
     *
     * @return void
     */
    public function report(): void
    {
        Log::error('eHealth API Validation Error Detail', [
            'message' => $this->getMessage(),
            'details' => $this->details,
        ]);
    }

    /**
     * Log the exception and flash a user-facing error message.
     *
     * @param  string  $logMessage
     * @param  string|null  $flashMessage  Optional override for the user-facing flash message
     * @return void
     */
    public function handle(string $logMessage, ?string $flashMessage = null): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];

        Log::channel('e_health_errors')->error($logMessage, [
            'class' => $caller['class'] ?? 'unknown_class',
            'method' => $caller['function'] ?? 'unknown_method',
            'exception_type' => static::class,
            'error_message' => $this->getDetails()
        ]);

        Session::flash('error', $flashMessage ?? $this->getFormattedMessage());
    }

    /**
     * Get the full details of the exception.
     *
     * @return array
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * Get a formatted error message including details from the eHealth response.
     *
     * @return string
     */
    public function getFormattedMessage(): string
    {
        $type = $this->details['error']['type'] ?? null;
        $errorMessage = $this->details['error']['message'] ?? null;

        $translated = match (true) {
            $type === 'validation_failed' => '',
            $errorMessage === 'Care plan has unfinished activities' => 'План лікування має незавершені призначення (активності). Спочатку скасуйте або завершіть усі призначення в цьому плані.',
            $errorMessage !== null => $errorMessage,
            default => $this->getMessage()
        };

        $message = 'Помилка від ЕСОЗ:' . ($translated !== '' ? ' ' . $translated : '');

        if (isset($this->details['error']['invalid']) && is_array($this->details['error']['invalid'])) {
            $invalids = $this->details['error']['invalid'];

            $errors = collect($invalids)
                ->map(function ($item) {
                    $entry = $item['entry'] ?? 'unknow field';
                    $description = $item['rules'][0]['description'] ?? 'no description';

                    if (str_contains($entry, 'product_reference.identifier.value') && str_contains($description, 'Value is not allowed by prescribable_device_codes dictionary configuration')) {
                        return 'Код медичного виробу: Вибраний код не дозволений поточною конфігурацією словника для призначень в ЕСОЗ';
                    }
                    if (str_contains($entry, 'program.identifier.value') && str_contains($description, 'No appropriate participants found for this medical program')) {
                        return 'Медична програма: Не знайдено відповідних учасників (закладів або підрозділів) для обраної медичної програми';
                    }

                    return "$entry: $description";
                })
                ->implode(', ');

            $message .= " ($errors)";
        }

        return $message;
    }

    /**
     * Get the translated error message based on eHealth details.
     *
     * @return string
     */
    public function getTranslatedMessage(): string
    {
        $eHealthFieldTranslations = [
            'party.first_name' => __('forms.first_name'),
            'party.last_name' => __('forms.last_name'),
            'party.second_name' => __('forms.second_name'),
            'party.birth_date' => __('forms.birth_date'),
            'party.tax_id' => __('forms.tax_id'),
            'party.working_experience' => __('forms.working_experience'),
            'doctor' => __('forms.doctor_data'),
            'start_date' => __('forms.start_date_work'),
            'employee_type' => __('forms.role'),
            'position' => __('forms.position'),
            'employee_request' => __('forms.employee_requests'),
            'doctor.science_degree' => __('forms.science_degree'),
            'party.documents.[0].number' => __('forms.document_number'),
            'party.documents.*.number' => __('forms.document_number'),
            'party.documents.*.type' => __('forms.document_type'),
            'party.documents.*.issued_by' => __('forms.document_issued_by'),
            'party.documents.*.issued_at' => __('forms.document_issued_at'),
            'party.phones.*.number' => __('forms.phone_number'),
            'party.phones.*.type' => __('forms.phone_type'),
            'doctor.qualifications' => __('forms.qualifications'),
            'doctor.specialities' => __('forms.specialities'),
            'doctor.specialities.speciality_officio' => __('forms.speciality_officio'),
            'doctor.specialities.*.speciality' => __('forms.speciality'),
            'doctor.specialities.*.speciality_officio' => __('forms.speciality_officio'),
            'doctor.specialities.*.attestation_name' => __('forms.issued_by'),
            'doctor.specialities.*.level' => __('forms.speciality_level'),
            'doctor.educations.*.city' => __('forms.city'),
            'doctor.educations.*.institution_name' => __('forms.institutionName'),
            'doctor.educations.*.speciality' => __('forms.speciality'),
            'code.coding[0].value' => __('care-plan.ehealth_fields.service_or_device_code'),
            'code.coding[0]' => __('care-plan.ehealth_fields.service_or_device_code'),
            'based_on[1].identifier.value' => __('care-plan.ehealth_fields.based_on_activity'),
            'quantity.code' => __('care-plan.ehealth_fields.quantity_code'),
            'requester.identifier.value' => __('care-plan.ehealth_fields.requester'),
            'authored_on' => __('care-plan.ehealth_fields.authored_on'),
            'medical_programs.[0]' => 'Медична програма',
            'medical_programs' => 'Медична програма',
            'detail.product_reference.identifier.value' => 'Код медичного виробу',
            'product_reference.identifier.value' => 'Код медичного виробу',
            'detail.program.identifier.value' => 'Медична програма',
            'program.identifier.value' => 'Медична програма',
            'device_definition' => 'Медичний виріб',
            'prescribable_device_codes' => 'Дозволені медичні вироби для призначення',
        ];

        $invalidErrors = Arr::get($this->details, 'error.invalid') ?? Arr::get($this->details, 'invalid') ?? [];

        $errorList = collect($invalidErrors)->map(function ($detail) use ($eHealthFieldTranslations): ?string {
            $eHealthKey = Arr::get($detail, 'entry') ?? Arr::get($detail, 'param') ?? 'unknown';
            $message = Arr::get($detail, 'rules.0.description') ?? Arr::get($detail, 'msg') ?? '';
            if (empty($message)) {
                $message = Arr::get($this->details, 'error.message') ?? Arr::get($this->details, 'message') ?? '';
            }
            $ruleName = Arr::get($detail, 'rules.0.rule');

            if ($eHealthKey === 'status') {
                return null;
            }

            $eHealthKey = str_replace(['$.', 'employee_request.'], '', $eHealthKey);
            $normalizedKey = preg_replace('/\.?\[\d+\]/', '.*', $eHealthKey);
            $translatedKey = $eHealthFieldTranslations[$normalizedKey] ?? $eHealthFieldTranslations[$eHealthKey] ?? $eHealthKey;

            $translatedMessage = '';

            if (str_contains($message, 'employee doesn\'t have speciality with active speciality_officio')) {
                $translatedMessage = __(
                    'errors.ehealth.messages.employee doesn\'t have speciality with active speciality_officio'
                );
            } elseif (str_contains($message, 'employee have more than one speciality with active speciality_officio')) {
                $translatedMessage = __(
                    'errors.ehealth.messages.multiple_primary_specialities'
                );
            } elseif (str_contains($message, 'speciality') && str_contains(
                $message,
                ' with active speciality_officio is not allowed for doctor'
            )) {
                preg_match(
                    '/speciality (.+?) with active speciality_officio is not allowed for doctor/',
                    $message,
                    $matches
                );
                $specialityName = $matches[1] ?? '';
                $translatedMessage = __(
                    'errors.ehealth.messages.speciality_officio_not_allowed',
                    ['speciality' => $specialityName]
                );
            } elseif (str_contains($message, 'speciality') && str_contains($message, 'not allowed for doctor')) {
                $translatedMessage = __('errors.ehealth.messages.speciality not allowed for doctor');
            } elseif (str_contains($message, 'type mismatch')) {
                $messages = trans('errors.ehealth.messages');
                $translatedMessage = is_array($messages) && isset($messages[$message])
                    ? $messages[$message]
                    : $message;
            } elseif (str_contains($message, 'Another activity with status') && str_contains($message, 'already exists')) {
                $translatedMessage = __('errors.ehealth.messages.another_activity_exists');
            } elseif (str_contains($message, 'Activity not found')) {
                $translatedMessage = __('errors.ehealth.messages.activity_not_found_in_ehealth');
            } elseif (str_contains($message, 'Requester doesn\'t match with encounter performer')) {
                $translatedMessage = __('errors.ehealth.messages.requester_encounter_mismatch');
            } elseif (str_contains($message, 'Not found any active Device Definition')) {
                $translatedMessage = $message;
            } elseif (str_contains($message, 'Authored on date must be in range')) {
                $translatedMessage = __('errors.ehealth.messages.authored_on_out_of_range');
            } elseif (str_contains($message, 'Medical program is not allowed for this action')) {
                $translatedMessage = __('errors.ehealth.messages.medical_program_not_allowed');
            } elseif (str_contains($message, 'Value is not allowed by prescribable_device_codes dictionary configuration')) {
                $translatedMessage = 'Вибраний код медичного виробу не дозволений поточною конфігурацією словника для призначень в ЕСОЗ';
            } elseif (str_contains($message, 'No appropriate participants found for this medical program')) {
                $translatedMessage = 'Не знайдено відповідних учасників (закладів або підрозділів) для обраної медичної програми';
            } elseif (str_contains($message, 'Code field of daily_amount object should be equal to denumerator_unit of one of medication\'s innms')) {
                $translatedMessage = 'Код одиниці добової дози (daily_amount) повинен збігатися з denumerator_unit одного з INNM обраного лікарського засобу';
            } elseif (str_contains($message, 'Activity can be completed only if it has in_progress status')) {
                $translatedMessage = 'Призначення може бути виконане тільки якщо воно має статус "В процесі" (in_progress)';
            } elseif (!empty($message)) {
                $translatedMessage = $message;
            }

            if (empty($translatedMessage) && !empty($ruleName)) {
                $translatedMessage = __('errors.ehealth.messages.' . $ruleName);
                if ($translatedMessage === 'errors.ehealth.messages.' . $ruleName) {
                    $translatedMessage = $message;
                }
            }

            if (empty($translatedMessage) && !empty($message)) {
                $translatedMessage = $message;
            }

            if (empty($translatedMessage)) {
                $fallbackMessage = $message ?: (Arr::get($this->details, 'error.message') ?? __('errors.ehealth.messages.request_error'));
                $translatedMessage = __('errors.ehealth.messages.untranslated_error_message', ['message' => $fallbackMessage]);
            }

            if ($eHealthKey === 'unknown') {
                return (string) $translatedMessage;
            }

            return "{$translatedKey}: {$translatedMessage}";
        })->filter()->implode("\n");

        if (empty($errorList)) {
            $mainMessage = Arr::get($this->details, 'error.message') ?? Arr::get($this->details, 'message') ?? '';
            if (!empty($mainMessage)) {
                $errorList = (string) $mainMessage;
            }
        }

        $header = __('errors.ehealth.validation_error_header');

        return "{$header}\n{$errorList}";
    }

    /**
     * Check if the validation error is a duplicate referral error.
     *
     * @return bool
     */
    public function isDuplicateReferralError(): bool
    {
        $invalidErrors = Arr::get($this->details, 'error.invalid') ?? Arr::get($this->details, 'invalid') ?? [];

        foreach ($invalidErrors as $detail) {
            $message = Arr::get($detail, 'rules.0.description') ?? Arr::get($detail, 'msg') ?? '';
            if (str_contains($message, 'already exists') || str_contains($message, 'duplicate')) {
                return true;
            }
        }

        $message = $this->details['error']['message'] ?? '';
        if (str_contains($message, 'already exists') || str_contains($message, 'duplicate')) {
            return true;
        }

        return false;
    }
}
