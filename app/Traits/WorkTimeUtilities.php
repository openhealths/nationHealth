<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Division;

trait WorkTimeUtilities
{
    public array $weekdays = [
        'mon' => 'Понеділок',
        'tue' => 'Вівторок',
        'wed' => 'Середа',
        'thu' => 'Четвер',
        'fri' => 'П’ятниця',
        'sat' => 'Субота',
        'sun' => 'Неділя'
    ];

    public array $workingHours = [
        'mon' => [[Division::WORKING_TIME_DEFAULT_START, Division::WORKING_TIME_DEFAULT_END]],
        'tue' => [[Division::WORKING_TIME_DEFAULT_START, Division::WORKING_TIME_DEFAULT_END]],
        'wed' => [[Division::WORKING_TIME_DEFAULT_START, Division::WORKING_TIME_DEFAULT_END]],
        'thu' => [[Division::WORKING_TIME_DEFAULT_START, Division::WORKING_TIME_DEFAULT_END]],
        'fri' => [[Division::WORKING_TIME_DEFAULT_START, Division::WORKING_TIME_DEFAULT_END]],
        'sat' => [[Division::WORKING_TIME_DEFAULT_START, Division::WORKING_TIME_DEFAULT_END]],
        'sun' => [[Division::WORKING_TIME_DEFAULT_START, Division::WORKING_TIME_DEFAULT_END]]
    ];

    /**
     * Replace colon ':' to dot '.' symbol
     *
     * @param  string  $str
     * @return string
     */
    protected function changeColonToDot(string $str): string
    {
        return str_replace(':', '.', $str);
    }

    /**
     * Replace dot '.' to colon ':' symbol
     *
     * @param  string  $str
     * @return string
     */
    protected function changeDotToColon(string $str): string
    {
        return str_replace('.', ':', $str);
    }

    /**
     * Time format commonly divided by colon ':' but in some RARE cases some resources want to see
     * the divider as dot '.'
     *
     * @param  array  $arr  // Array with time pairs
     * @param  bool  $dotToColon  // Flag to switch replacement from colon ':' to dot '.' and vice versa
     * @return array
     */
    protected function changeTimeFormat(array $arr, bool $dotToColon): array
    {
        if (empty($arr)) {
            return [];
        }

        return array_map(function ($item) use ($dotToColon) {
            if (is_array($item)) {
                return $this->changeTimeFormat($item, $dotToColon);
            }

            return $dotToColon ? $this->changeDotToColon($item) : $this->changeColonToDot($item);
        }, $arr);
    }

    /**
     * Go through all incoming array values and replace divider for time format for all founded values
     *
     * @param  array  $arr  // Array with time data values
     * @param  bool  $dotToColon  // Switcher determine what replace and to
     * @return array
     */
    public function prepareTimeToRequest(array $arr, bool $dotToColon): array
    {
        return array_map(fn ($item) => $this->changeTimeFormat($item, $dotToColon), $arr);
    }
}
