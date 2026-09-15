<?php

declare(strict_types=1);

namespace App\Exceptions\EHealth;

/**
 * Maps known eHealth English rule messages (and numeric codes) to Ukrainian copy.
 */
final class EHealthErrorTranslator
{
    public static function translate(string $message): string
    {
        $message = trim($message);

        if ($message === '') {
            return $message;
        }

        $body = $message;

        if (preg_match('/^(\d{3,5}):\s*(.+)$/u', $message, $matches) === 1) {
            $ruleKey = 'errors.ehealth.rules.'.$matches[1];
            $byCode = __($ruleKey);

            if ($byCode !== $ruleKey) {
                return $byCode;
            }

            $body = $matches[2];
        }

        if (str_contains($body, 'Treatment violation date should be')) {
            return __('errors.ehealth.messages.treatment_violation_date_range');
        }

        if (str_contains($body, 'Existing composition has start date after suggested start date')
            || str_contains($body, 'NEW_START_DATE_IS_BEFORE_PREVIOUS_START_DATE')) {
            return __('errors.ehealth.messages.new_start_date_before_previous');
        }

        return $message;
    }
}
