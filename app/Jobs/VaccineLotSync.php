<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\MedData\VaccineLot;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class VaccineLotSync implements ShouldQueue
{
    use Queueable;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 120;

    /**
     * Refresh cached vaccine lots from MedData; on failure the previously cached lots stay available.
     *
     * @param  VaccineLot  $vaccineLotService
     * @return void
     */
    public function handle(VaccineLot $vaccineLotService): void
    {
        try {
            $vaccineLotService->sync();
        } catch (Exception $exception) {
            Log::channel('task_scheduling')->error('MedData vaccine lots sync failed.', [
                'message' => $exception->getMessage()
            ]);
        }
    }
}
