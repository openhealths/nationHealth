<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\CarePlanRepository;

class BackfillCarePlanFhir extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'care-plans:backfill-fhir {--legal-entity= : Required local legal entity ID} {--write : Apply conversion after a successful preflight}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect or convert legacy Care Plan drafts; registered resources require a full ESOZ sync';

    /**
     * Execute the console command.
     */
    public function handle(CarePlanRepository $repository): int
    {
        $legalEntityId = filter_var($this->option('legal-entity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($legalEntityId === false) {
            $this->error('Specify a positive local --legal-entity ID.');

            return self::INVALID;
        }
        $report = $repository->backfillResources($legalEntityId, (bool) $this->option('write'));
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $report['errors'] === [] && $report['needsRemoteSync'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
