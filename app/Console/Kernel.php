<?php

declare(strict_types=1);

namespace App\Console;

use App\Jobs\ConfigurationMetadataSync;
use App\Jobs\UpdateICD10TableJob;
use App\Jobs\VaccineLotSync;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new UpdateICD10TableJob())->weekly();
        $schedule->job(new ConfigurationMetadataSync())->twiceDaily()->withoutOverlapping();
        $schedule->job(new VaccineLotSync())->hourly()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
