<?php

declare(strict_types=1);

namespace App\Livewire\DiagnosticReport;

use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\Person\Person;
use App\Models\Preperson;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

class DiagnosticReportView extends DiagnosticReportEdit
{
    /**
     * Edit page of a draft that is not waiting for eHealth.
     *
     * @var string|null
     */
    #[Locked]
    public ?string $editUrl = null;

    /**
     * Load the report for viewing, offering its edit page while it can still be changed.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Person|null  $person
     * @param  Preperson|null  $preperson
     * @param  DiagnosticReport|null  $diagnosticReport
     * @return void
     */
    public function mount(
        LegalEntity $legalEntity,
        ?Person $person = null,
        ?Preperson $preperson = null,
        ?DiagnosticReport $diagnosticReport = null
    ): void {
        parent::mount($legalEntity, $person, $preperson, $diagnosticReport);

        // The edit page leaves only a draft that is not waiting for eHealth editable
        $this->editUrl = $this->isReadonly ? null : $this->editPageUrl();

        $this->isReadonly = true;
    }

    /**
     * Offer the edit page once eHealth rejected the submitted report, which stays a draft to fix.
     *
     * @return void
     */
    #[On('submission-rejected')]
    public function offerEditing(): void
    {
        $this->editUrl = $this->editPageUrl();
    }

    /**
     * Get the edit page of the report.
     *
     * @return string
     */
    private function editPageUrl(): string
    {
        return route(
            $this->prepersonId !== null ? 'prepersons.diagnostic-report.edit' : 'diagnostic-report.edit',
            $this->prepersonId !== null
                ? [legalEntity(), 'preperson' => $this->prepersonId, 'diagnosticReport' => $this->diagnosticReportId]
                : [legalEntity(), 'person' => $this->personId, 'diagnosticReport' => $this->diagnosticReportId]
        );
    }

    /**
     * Render the report form, which is readonly here.
     *
     * @return View
     */
    public function render(): View
    {
        return view('livewire.diagnostic-report.diagnostic-report-view');
    }
}
