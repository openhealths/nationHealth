<?php

declare(strict_types=1);

namespace App\Enums\Composition;

use App\Classes\eHealth\SchemaResponseContract;
use App\Exceptions\MedicalEvents\CompositionGuardException;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

/** Validated MIS mirror of the legacy service's environment-specific EMAL parameters. */
enum CompositionConfiguration: string
{
    case AUTHOR_POSITIONS = 'EMAL_VALIDATION_AUTHOR_CATEGORIES_BY_POSITION';
    case ENCOUNTER_AGE = 'EMAL_VALIDATION_ENCOUNTER_MAX_AGE_DAYS_BY_CATEGORY';
    case DISABILITY_CATEGORIES = 'EMAL_VALIDATION_CATEGORY_DISABILITY_CODES';
    case NEWBORN_CATEGORIES = 'EMAL_VALIDATION_CATEGORY_NEWBORN_CODES';
    case EXTENSIONS = 'EMAL_VALIDATION_EXTENSION_ALLOWED_BY_CATEGORY';
    case EQUALITY = 'EMAL_VALIDATION_SUBJECT_FOCUS_REQUIRE_EQUALITY_BY_CATEGORY';
    case SINGLE_SPAN = 'EMAL_FILTER_DISABILITY_SINGLE_SPAN_DISABILITY';
    case SINGLE_SPAN_ENABLED = 'EMAL_FILTER_DISABILITY_SINGLE_SPAN_DISABILITY_ENABLED';
    case DISABILITY_START = 'EMAL_FILTER_PERIOD_START_DISABILITY';
    case DISABILITY_START_ENABLED = 'EMAL_FILTER_PERIOD_START_DISABILITY_ENABLED';
    case NEWBORN_START = 'EMAL_FILTER_PERIOD_START_NEWBORN';
    case NEWBORN_START_ENABLED = 'EMAL_FILTER_PERIOD_START_NEWBORN_ENABLED';
    case CANCEL_DAYS = 'EMAL_FILTER_CANCEL_TIMEOUT_DISABILITY';
    case CANCEL_ENABLED = 'EMAL_FILTER_CANCEL_TIMEOUT_DISABILITY_ENABLED';
    case REPLACE_ENABLED = 'EMAL_COMPOSITION_FLOW_REPLACE_ENABLED';
    case REPLACE_REASONS = 'EMAL_REPLACEMENT_FLOW_DISABILITY_ALLOWED_CANCELLATION_REASONS';
    case REPLACE_CANCEL_DAYS = 'EMAL_FILTER_REPLACEMENT_CANCEL_TIMEOUT_DISABILITY';
    case PREGNANCY_NEW = 'EMAL_VALIDATION_PREGNANCY_NEW_COMPOSITION_ALLOWED_PERIODS';
    case PREGNANCY_APPENDED = 'EMAL_VALIDATION_PREGNANCY_APPENDED_COMPOSITION_ALLOWED_PERIOD';
    case AUTHOR_STATUS = 'EMAL_VALIDATION_AUTHOR_ACTIVE_STATUS_VALUE';
    case AUTHOR_TYPES = 'EMAL_VALIDATION_AUTHOR_CATEGORIES_BY_TYPE';
    case CUSTODIAN_TYPES = 'EMAL_VALIDATION_CUSTODIAN_CATEGORIES_BY_TYPE';
    case CUSTODIAN_DISABILITY_STATUSES = 'EMAL_VALIDATION_CUSTODIAN_ALLOWED_STATUSES_DISABILITY';
    case CUSTODIAN_NEWBORN_STATUSES = 'EMAL_VALIDATION_CUSTODIAN_ALLOWED_STATUSES_NEWBORN';
    case SUBJECT_TYPES = 'EMAL_VALIDATION_SUBJECT_CATEGORIES_BY_TYPE';
    case FOCUS_TYPES = 'EMAL_VALIDATION_FOCUS_CATEGORIES_BY_TYPE';
    case SUBJECT_STATUSES = 'EMAL_VALIDATION_SUBJECT_ALLOWED_STATUSES_BY_CATEGORY';
    case FOCUS_STATUSES = 'EMAL_VALIDATION_FOCUS_ALLOWED_STATUSES_BY_CATEGORY';
    case SUBJECT_VERIFICATION = 'EMAL_VALIDATION_SUBJECT_ALLOWED_VERIFICATION_STATUSES_BY_CATEGORY';
    case FOCUS_VERIFICATION = 'EMAL_VALIDATION_FOCUS_ALLOWED_VERIFICATION_STATUSES_BY_CATEGORY';
    case SUBJECT_AGES = 'EMAL_VALIDATION_SUBJECT_ALLOWED_AGES_BY_CATEGORY';
    case FOCUS_AGES = 'EMAL_VALIDATION_FOCUS_ALLOWED_AGES_BY_CATEGORY';
    case SUBJECT_GENDERS = 'EMAL_VALIDATION_SUBJECT_GENDERS_BY_CATEGORY';
    case FOCUS_GENDERS = 'EMAL_VALIDATION_FOCUS_GENDERS_BY_CATEGORY';
    case SUBJECT_DOCUMENTS = 'EMAL_VALIDATION_SUBJECT_DOCUMENTS_BY_CATEGORY';
    case FOCUS_DOCUMENTS = 'EMAL_VALIDATION_FOCUS_DOCUMENTS_BY_CATEGORY';
    case FOREIGN_DOCUMENTS = 'IDENTITY_DOCUMENT_TYPES_FOREIGN';
    case ERLN_DOCUMENTS = 'EMAL_ERLN_CREATE_RECORD_JOB_DOCUMENT_TYPE_CODES';
    case TOTAL_SPAN = 'EMAL_FILTER_DISABILITY_TOTAL_SPAN_DISABILITY';
    case TOTAL_SPAN_ENABLED = 'EMAL_FILTER_DISABILITY_TOTAL_SPAN_DISABILITY_ENABLED';
    case DISABILITY_DIVISION = 'EMAL_FILTER_DIVISION_REQUIREMENTS_DISABILITY';
    case DISABILITY_DIVISION_ENABLED = 'EMAL_FILTER_DIVISION_REQUIREMENTS_DISABILITY_ENABLED';
    case NEWBORN_DIVISION = 'EMAL_FILTER_DIVISION_REQUIREMENTS_NEWBORN';
    case NEWBORN_DIVISION_ENABLED = 'EMAL_FILTER_DIVISION_REQUIREMENTS_NEWBORN_ENABLED';
    case DISABILITY_ENCOUNTER_TYPES = 'EMAL_FILTER_ENCOUNTER_TYPE_DISABILITY';
    case DISABILITY_ENCOUNTER_TYPES_ENABLED = 'EMAL_FILTER_ENCOUNTER_TYPE_DISABILITY_ENABLED';
    case NEWBORN_ENCOUNTER_TYPES = 'EMAL_FILTER_ENCOUNTER_TYPE_NEWBORN';
    case NEWBORN_ENCOUNTER_TYPES_ENABLED = 'EMAL_FILTER_ENCOUNTER_TYPE_NEWBORN_ENABLED';
    case DISABILITY_DIAGNOSES = 'EMAL_FILTER_ENCOUNTER_DIAGNOSES_DISABILITY';
    case DISABILITY_DIAGNOSES_ENABLED = 'EMAL_FILTER_ENCOUNTER_DIAGNOSES_DISABILITY_ENABLED';
    case NEWBORN_DIAGNOSES = 'EMAL_FILTER_ENCOUNTER_DIAGNOSES_NEWBORN';
    case NEWBORN_DIAGNOSES_ENABLED = 'EMAL_FILTER_ENCOUNTER_DIAGNOSES_NEWBORN_ENABLED';
    case DISABILITY_AGE = 'EMAL_FILTER_AGE_DISABILITY';
    case DISABILITY_AGE_ENABLED = 'EMAL_FILTER_AGE_DISABILITY_ENABLED';
    case NEWBORN_AGE = 'EMAL_FILTER_AGE_NEWBORN';
    case NEWBORN_AGE_ENABLED = 'EMAL_FILTER_AGE_NEWBORN_ENABLED';
    case DISABILITY_GENDER = 'EMAL_FILTER_GENDER_DISABILITY';
    case DISABILITY_GENDER_ENABLED = 'EMAL_FILTER_GENDER_DISABILITY_ENABLED';
    case NEWBORN_GENDER = 'EMAL_FILTER_GENDER_NEWBORN';
    case NEWBORN_GENDER_ENABLED = 'EMAL_FILTER_GENDER_NEWBORN_ENABLED';

    case AUTHOR_SPECIALITIES = 'EMAL_VALIDATION_AUTHOR_CATEGORIES_BY_SPECIALITY';
    case AUTHOR_SPECIALITIES_ENABLED = 'EMAL_VALIDATION_AUTHOR_CATEGORIES_BY_SPECIALITY_ENABLED';
    case AUTHOR_OFFICIO = 'EMAL_VALIDATION_AUTHOR_CATEGORIES_BY_SPECIALITY_OFFICIO';
    case DISABILITY_DOCTOR_SPECIALITIES = 'EMAL_FILTER_DOCTOR_SPECIALITY_DISABILITY';
    case DISABILITY_DOCTOR_SPECIALITIES_ENABLED = 'EMAL_FILTER_DOCTOR_SPECIALITY_DISABILITY_ENABLED';
    case NEWBORN_DOCTOR_SPECIALITIES = 'EMAL_FILTER_DOCTOR_SPECIALITY_NEWBORN';
    case NEWBORN_DOCTOR_SPECIALITIES_ENABLED = 'EMAL_FILTER_DOCTOR_SPECIALITY_NEWBORN_ENABLED';
    case DISABILITY_DUPLICATE_ENABLED = 'EMAL_FILTER_PREPERSON_DUPLICATE_DISABILITY_ENABLED';
    case NEWBORN_DUPLICATE_ENABLED = 'EMAL_FILTER_PREPERSON_DUPLICATE_NEWBORN_ENABLED';
    case DISABILITY_SIGN_DUPLICATE_ENABLED = 'EMAL_FILTER_SIGN_PREPERSON_DUPLICATE_DISABILITY_ENABLED';
    case NEWBORN_SIGN_DUPLICATE_ENABLED = 'EMAL_FILTER_SIGN_PREPERSON_DUPLICATE_NEWBORN_ENABLED';
    case DISABILITY_SIGN_DELAY = 'EMAL_FILTER_SIGN_CREATE_DELAY_DISABILITY';
    case NEWBORN_SIGN_DELAY = 'EMAL_FILTER_SIGN_CREATE_DELAY_NEWBORN';
    case DISABILITY_SIGN_DELAY_ENABLED = 'EMAL_FILTER_SIGN_CREATE_DELAY_DISABILITY_ENABLED';
    case NEWBORN_SIGN_DELAY_ENABLED = 'EMAL_FILTER_SIGN_CREATE_DELAY_NEWBORN_ENABLED';
    case DISABILITY_REPLACEMENT_START = 'EMAL_FILTER_REPLACEMENT_PERIOD_START_DISABILITY';
    case NEWBORN_REPLACEMENT_START = 'EMAL_FILTER_REPLACEMENT_PERIOD_START_NEWBORN';

    public function value(?string $index = null): mixed
    {
        $settings = config('compositions.emal');
        if (is_string($settings)) {
            try {
                $settings = json_decode($settings, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new CompositionGuardException(__('compositions.errors.configuration_unavailable'));
            }
        }

        if (!is_array($settings) || array_is_list($settings) || !array_key_exists($this->value, $settings)) {
            throw new CompositionGuardException(__('compositions.errors.configuration_unavailable'));
        }

        try {
            $schema = json_decode(file_get_contents(resource_path('ehealth/contracts/composition-emal-config.schema.json')), true, flags: JSON_THROW_ON_ERROR);
            SchemaResponseContract::validateData($settings, $schema);
        } catch (JsonException | ValidationException) {
            throw new CompositionGuardException(__('compositions.errors.configuration_unavailable'));
        }

        $value = $settings[$this->value];
        if ($index !== null) {
            if (!is_array($value) || !array_key_exists($index, $value)) {
                throw new CompositionGuardException(__('compositions.errors.configuration_unavailable'));
            }
            $value = $value[$index];
        }

        return $value;
    }

    public function days(?string $category = null): int
    {
        $value = $this->value($category);
        if (!is_int($value) || $value < 0) {
            throw new CompositionGuardException(__('compositions.errors.configuration_unavailable'));
        }

        return $value;
    }

    public function enabled(?string $category = null): bool
    {
        $value = $this->value($category);
        if (!is_bool($value)) {
            throw new CompositionGuardException(__('compositions.errors.configuration_unavailable'));
        }

        return $value;
    }

    /** @return list<string> */
    public function codes(?string $category = null, bool $allowEmpty = false): array
    {
        $values = $this->value($category);
        if (!is_array($values) || !array_is_list($values) || (!$allowEmpty && $values === [])
            || count(array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== '')) !== count($values)) {
            throw new CompositionGuardException(__('compositions.errors.configuration_unavailable'));
        }

        return $values;
    }

    /** Optional category restrictions explicitly allow an absent category or a null map. */
    public function optionalCodes(string $category): ?array
    {
        $map = $this->value();

        return $map === null || !array_key_exists($category, $map) ? null : $this->codes($category, allowEmpty: true);
    }

    /** EMAL range strings are mirrored as [inclusive minimum, inclusive maximum], with null for no bound. */
    public function assertAgeAllowed(string $category, int $age): void
    {
        [$min, $max] = $this->value($category);
        if ($min !== null && $max !== null && $max < $min) {
            throw new CompositionGuardException(__('compositions.errors.configuration_unavailable'));
        }
        if (($min !== null && $age < $min) || ($max !== null && $age > $max)) {
            throw new CompositionGuardException(__('compositions.errors.participant_age_not_allowed'));
        }
    }

    /** A day-based timeout uses calendar dates, inclusive of the configured last day. */
    public static function assertCancellationAllowed(?string $signedDate, ?string $reason = null): void
    {
        if (!self::CANCEL_ENABLED->enabled()) {
            return;
        }
        $days = self::CANCEL_DAYS->days();
        if ($reason !== null && self::REPLACE_ENABLED->enabled()
            && in_array($reason, self::REPLACE_REASONS->codes(allowEmpty: true), true)) {
            $days = self::REPLACE_CANCEL_DAYS->days();
        }
        if (blank($signedDate)) {
            throw new CompositionGuardException(__('compositions.errors.configuration_unavailable'));
        }
        try {
            $signed = CarbonImmutable::parse($signedDate)->setTimezone(config('app.timezone'))->startOfDay();
        } catch (Throwable) {
            throw new CompositionGuardException(__('compositions.errors.configuration_unavailable'));
        }
        if ($signed->isFuture() || $signed->addDays($days)->lt(CarbonImmutable::today())) {
            throw new CompositionGuardException(__('compositions.errors.cancel_timeout'));
        }
    }
}
