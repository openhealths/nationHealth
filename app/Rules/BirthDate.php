<?php

declare(strict_types=1);

/**
 * Checks the birthdate according to the ezdorovya specification: https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402887/Create+employee+request+v2
 */

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;
use Illuminate\Translation\PotentiallyTranslatedString;

class BirthDate implements ValidationRule
{
    protected string $email;

    public function __construct(string $email = '')
    {
        $this->email = $email;
    }

    protected const string MIN_DATE = '01.01.1900';

    /**
     * Run the validation rule.
     *
     * @param  string  $attribute  The name of the attribute being validated
     * @param  mixed  $value  The value of the attribute being validated
     * @param  Closure(string): PotentiallyTranslatedString  $fail  The callback to invoke if validation fails
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $birthDate = Carbon::parse($value);
        $minDate = Carbon::parse(self::MIN_DATE);

        if ($birthDate->lte($minDate) || $birthDate->gt(Carbon::now())) {
            $fail(__('validation.employee.party.birth_date_value'));
        }

        $user = User::where('email', $this->email)->first();

        /**
         *  Check OWNER's birth_date from request is equal to birth_date from it's party of OWNER's employee_id
         *  see: https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583403638/Create+Update+Legal+Entity+V2
         */
        if ($user?->party && !$birthDate->eq($user->party->birthDate)) {
            $fail(__('validation.employee.owner_date_mismatch'));
        }
    }
}
