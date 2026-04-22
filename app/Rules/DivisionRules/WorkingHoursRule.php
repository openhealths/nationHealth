<?php

namespace App\Rules\DivisionRules;

use Illuminate\Contracts\Validation\ValidationRule;
use App\Exceptions\CustomValidationException;
use App\Traits\WorkTimeUtilities;
use Carbon\Carbon;
use Closure;

class WorkingHoursRule implements ValidationRule
{
    use WorkTimeUtilities;

    protected array $division;

    protected string $message;

    public function __construct(array $division)
    {
        $this->division = $division;

        $this->message = __('divisions.errors.workingHours.commonError');
    }

    /**
     * Check that working hours schedule is correct
     * Here 'shift' is the working time period from start to the end
     * If shift's count is more or equal than 1, then the day is considered as a workday
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
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
     * Check if a shift is considered empty (non-working).
     *
     * A shift is considered empty if:
     * - It is null or empty array
     * - Both start and end times are '00:00', which indicates a day off
     *
     * @param mixed $shift The shift array to check, containing start and end times
     *
     * @return bool Returns true if the shift is empty (non-working), false otherwise
     */
    protected function checkEmptyShift(mixed $shift): bool
    {
        if (!is_array($shift) || !array_key_exists(0, $shift) || !array_key_exists(1, $shift)) {
            return true;
        }

        // If Start time = '00:00' and endTime = '00:00' it means that day is off
        // Also treat identical empty strings or nulls as off
        if ($shift[0] === $shift[1] && ($shift[0] === '00:00' || $shift[0] === '' || $shift[0] === null)) {
            return true;
        }

        return false;
    }

    /**
     * Check that all bunch of the workingHours' data is correct and valid
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    protected function throwError(string $shiftName = ''): void
    {
        throw new CustomValidationException($this->message($shiftName), 'custom');
    }

    /**
     * Throw a custom validation exception with the current error message.
     *
     * This method is called when a address type rule fails validation.
     *
     * @return void
     *
     * @throws CustomValidationException
     */
    protected function setMessage(string $message): void
    {
        $this->message = $message;
    }

    /**
     * Set the custom error message for the validation rule.
     *
     * This message will be used when throwing a validation exception.
     *
     * @param string $message The error message to set.
     *
     * @return void
     */
    protected function message(string $shiftName = ''): string
    {
        return $shiftName . $this->message;
    }

    /**
     * Check if the beginning of the shift start earlier than one's ending
     *
     * @param array $day
     *
     * @return bool
     */
    protected function compareTime(string $day, array $shift): bool
    {
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
     * Check if the beginning of the upcoming shift starts after previous one's ending
     *
     * @param string $prevShiftEnd      // The time the next shift will start
     * @param string $currShiftStart    // The time the previous shift ended
     *
     * @return bool
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
     * @param string|null $time
     * @return bool
     */
    protected function isValidTimeFormat(?string $time): bool
    {
        if (empty($time)) {
            return false;
        }

        return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time);
    }
}
