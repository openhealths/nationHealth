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
     * Log the exception and flash a user-facing error message.
     *
     * @param  string  $logMessage
     * @return void
     */
    public function handle(string $logMessage): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];

        Log::channel('e_health_errors')->error($logMessage, [
            'class' => $caller['class'] ?? 'unknown_class',
            'method' => $caller['function'] ?? 'unknown_method',
            'exception_type' => static::class,
            'error_message' => $this->getDetails()
        ]);

        Session::flash('error', $this->getFormattedMessage());
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
        $message = 'Помилка від ЕСОЗ: ' . $this->getMessage();

        if ($type === 'request_malformed') {
            return $message . ' ' . $this->details['error']['message'];
        }

        if (isset($this->details['error']['invalid']) && is_array($this->details['error']['invalid'])) {
            $invalids = $this->details['error']['invalid'];

            $errors = collect($invalids)
                ->map(function ($item) {
                    $entry = $item['entry'] ?? 'unknow field';
                    $description = $item['rules'][0]['description'] ?? 'no description';

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
            'doctor.qualifications' => __('forms.qualifications'),
            'doctor.specialities' => __('forms.specialities'),
            'doctor.specialities.speciality_officio' => __('forms.speciality_officio'),
        ];

        $invalidErrors = Arr::get($this->details, 'error.invalid') ?? Arr::get($this->details, 'invalid') ?? [];

        $errorList = collect($invalidErrors)->map(function ($detail) use ($eHealthFieldTranslations) {
            $eHealthKey = Arr::get($detail, 'entry') ?? Arr::get($detail, 'param') ?? 'unknown';
            $message = Arr::get($detail, 'rules.0.description') ?? Arr::get($detail, 'msg') ?? '';
            $ruleName = Arr::get($detail, 'rules.0.rule');

            if ($eHealthKey === 'status') {
                return null;
            }

            $eHealthKey = str_replace(['$.', 'employee_request.'], '', $eHealthKey);
            $translatedKey = $eHealthFieldTranslations[$eHealthKey] ?? $eHealthKey;

            $translatedMessage = '';

            if (str_contains($message, 'employee doesn\'t have speciality with active speciality_officio')) {
                $translatedMessage = __(
                    'errors.ehealth.messages.employee doesn\'t have speciality with active speciality_officio'
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
                $translatedMessage = __('errors.ehealth.messages.type mismatch. Expected integer but got string');
            }

            if (empty($translatedMessage) && !empty($ruleName)) {
                $translatedMessage = __('errors.ehealth.messages.' . $ruleName);
                if ($translatedMessage === 'errors.ehealth.messages.' . $ruleName) {
                    $translatedMessage = $message;
                }
            }

            if (empty($translatedMessage)) {
                $translatedMessage = __('errors.ehealth.messages.untranslated_error_message', ['message' => $message]);
            }

            return "{$translatedKey}: {$translatedMessage}";
        })->filter()->implode("\n");

        $header = __('errors.ehealth.validation_error_header');

        return "{$header}\n{$errorList}";
    }
}
