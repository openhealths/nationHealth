<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\MedicalEvents\ServiceRequestRequestRepository;
use Illuminate\Console\Command;

class BackfillServiceRequestFhir extends Command
{
    protected $signature = 'service-requests:backfill-fhir {--person= : Local patient ID, required} {--write : Convert recoverable local drafts after preflight}';
    protected $description = 'Preflight or convert Service Request drafts without inventing missing clinical values';

    public function handle(ServiceRequestRequestRepository $repository): int
    {
        $personId = filter_var($this->option('person'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($personId === false) {
            $this->error('A positive local --person ID is required.');

            return self::INVALID;
        }
        $report = $repository->backfillResources($personId, (bool) $this->option('write'));
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $report['errors'] === [] && $report['needsRemoteSync'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
