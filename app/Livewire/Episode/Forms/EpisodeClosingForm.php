<?php

declare(strict_types=1);

namespace App\Livewire\Episode\Forms;

use App\Rules\AfterOrEqualDateTime;
use App\Rules\InDictionary;
use App\Rules\PastDateTime;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Form;

class EpisodeClosingForm extends Form
{
    /**
     * eHealth ID of the episode being closed.
     *
     * @var string
     */
    #[Locked]
    public string $closingId = '';

    /**
     * Moment the episode was opened at, as the `d.m.Y H:i` the period start is cast to.
     *
     * @var string
     */
    #[Locked]
    public string $periodStart = '';

    public string $closingDate = '';

    public string $closingTime = '';

    public string $closingReason = '';

    public string $closingSummary = '';

    /**
     * Rules for closing an episode.
     * The episode cannot end before it started, so the period start is the lower bound of the closing moment.
     *
     * @return array
     */
    protected function rules(): array
    {
        return [
            'closingDate' => ['required', 'date', 'before_or_equal:today'],
            'closingTime' => [
                'required',
                'date_format:H:i',
                new PastDateTime($this->closingDate),
                new AfterOrEqualDateTime(
                    $this->closingDate,
                    Str::before($this->periodStart, ' '),
                    Str::after($this->periodStart, ' '),
                    'episode_period_start'
                )
            ],
            'closingReason' => ['required', 'string', new InDictionary('eHealth/episode_closing_reasons')],
            'closingSummary' => ['nullable', 'string', 'max:1000']
        ];
    }

    /**
     * Redefine field names for error messages.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        return [
            'closingDate' => __('episodes.close_date_label'),
            'closingTime' => __('episodes.close_time_label'),
            'closingReason' => __('episodes.close_reason_label'),
            'closingSummary' => __('episodes.close_summary_label')
        ];
    }
}
