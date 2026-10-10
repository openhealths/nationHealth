<?php

declare(strict_types=1);

namespace App\Rules\DivisionRules;

use Closure;
use Carbon\Carbon;
use App\Models\Division;
use App\Traits\WorkTimeUtilities;
use App\Exceptions\CustomValidationException;
use Illuminate\Contracts\Validation\ValidationRule;

class WorkingHoursRule implements ValidationRule
{
    use WorkTimeUtilities;

    /**
     * @var array{workingHours: array<string, list<array{0: string, 1: string}>>}
     */
    protected array $division;

    // The validation message for the current failed check.
    protected string $message;

    public function __construct(array $division)
    {
        $this->division = $division;

        $this->message = __('divisions.errors.workingHours.commonError');
    }

    /**
     * Validate every working interval in the weekly schedule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $workingHours = $this->division['workingHours'];

        foreach ($this->weekdays as $day => $dayName) {
            // If the shift is empty, then skip the day
            if ($this->checkEmptyShift($workingHours[$day][0] ?? null)) {
                continue;
            }

            // Check if the shifts used for this day
            $shifts = $workingHours[$day];
            // Is the day use the shifts in workdays
            $isShifts = count($shifts) > 1;
            // Time when previous shift is ended
            $prevShiftEnd = '';
            // Human presentation of the day's name
            $dayName = '[' . $this->weekdays[$day] . ' ] ';

            // Check shifts
            foreach ($shifts as $shiftNumber => $shift) {
                $shiftName = $isShifts ? $dayName . '(Зміна ' .  $shiftNumber + 1 . ') ' : $dayName;

                if (!$this->compareTime($dayName, $shift)) {
                    $this->throwError($shiftName);
                }

                if ($isShifts && $shiftNumber > 0 && $this->isShiftIntersected($prevShiftEnd, $shift[0])) {
                    $this->throwError($shiftName);
                }

                $prevShiftEnd = $shift[1];
            }
        }
    }

    /**
     * Determine whether the first shift should be skipped as non-working.
     *
     * A shift is considered empty if:
     * - It is missing or malformed
     * - Both start and end times are the day-off sentinel
     *
     * @param  mixed  $shift
     *
     * @return bool True when the shift should be skipped.
     */
    protected function checkEmptyShift(mixed $shift): bool
    {
        if (!\is_array($shift) || !\array_key_exists(0, $shift) || !\array_key_exists(1, $shift)) {
            return true;
        }

        return $shift[0] === Division::WORKING_TIME_DAY_OFF &&
               $shift[1] === Division::WORKING_TIME_DAY_OFF;
    }

    /**
     * Throw the current validation error for a shift.
     *
     * @param  string  $shiftName
     *
     * @return void
     *
     * @throws CustomValidationException
     */
    protected function throwError(string $shiftName = ''): void
    {
        throw new CustomValidationException($this->message($shiftName), 'custom');
    }

    /**
     * Set the validation message for the current failed check.
     *
     * @param  string  $message
     *
     * @return void
     */
    protected function setMessage(string $message): void
    {
        $this->message = $message;
    }

    /**
     * Build the validation message, optionally prefixed with a shift label.
     *
     * @param  string  $shiftName
     *
     * @return string
     */
    protected function message(string $shiftName = ''): string
    {
        return $shiftName . $this->message;
    }

    /**
     * Validate a shift's time format and chronological range.
     *
     * @param  array{0: string, 1: string}  $shift
     *
     * @return bool True when the shift has valid, non-equal start and end times.
     */
    protected function compareTime(string $day, array $shift): bool
    {
        if (($shift[0] ?? null) === ($shift[1] ?? null)) {
            $this->setMessage(__('divisions.errors.workingHours.sameTime'));

            return false;
        }

        if (!$this->isValidTimeFormat($shift[0] ?? null) || !$this->isValidTimeFormat($shift[1] ?? null)) {
            $this->setMessage(__('divisions.errors.workingHours.wrongFormat'));

            return false;
        }

        $startTime = Carbon::createFromFormat('H:i', $shift[0]);
        $endTime = Carbon::createFromFormat('H:i', $shift[1]);

        if ($startTime->gte($endTime)) {
            $this->setMessage(__('divisions.errors.workingHours.wrongRange')) ;

            return false;
        }

        return true;
    }

    /**
     * Check whether the current shift begins before the previous shift ends.
     *
     * @param  string  $prevShiftEnd
     * @param  string  $currShiftStart
     *
     * @return bool True when the shifts intersect or either time is invalid.
     */
    protected function isShiftIntersected(string $prevShiftEnd, string $currShiftStart): bool
    {
        if (!$this->isValidTimeFormat($currShiftStart) || !$this->isValidTimeFormat($prevShiftEnd)) {
            $this->setMessage(__('divisions.errors.workingHours.wrongFormat'));

            return true;
        }

        $startTime = Carbon::createFromFormat('H:i', $currShiftStart);
        $endTime = Carbon::createFromFormat('H:i', $prevShiftEnd);

        if ($startTime->lt($endTime)) {
            $this->setMessage(__('divisions.errors.workingHours.wrongShiftStart'));

            return true;
        }

        return false;
    }

    /**
     * Check if the time string is in valid H:i format.
     *
     * @param  string|null  $time
     *
     * @return bool True when the value is a time in 24-hour H:i format.
     */
    protected function isValidTimeFormat(?string $time): bool
    {
        if (empty($time)) {
            return false;
        }

        return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time);
    }
}
