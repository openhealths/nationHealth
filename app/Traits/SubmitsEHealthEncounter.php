<?php

declare(strict_types=1);

namespace App\Traits;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\Errors\ErrorHandler;
use App\Repositories\MedicalEvents\Repository;
use RuntimeException;

trait SubmitsEHealthEncounter
{
    /**
     * Polls the eHealth job status, formats errors if it fails, and syncs the encounter.
     *
     * @throws RuntimeException
     */
    protected function waitForEncounterJobAndSync(
        array $submitResponseData,
        string $patientUuid,
        string $encounterUuid,
        mixed $patientModel
    ): void {
        $jobId = $submitResponseData['job_id'] ?? null;
        if (!$jobId && isset($submitResponseData['links'][0]['href'])) {
            $jobId = basename($submitResponseData['links'][0]['href']);
        }
        if (!$jobId) {
            throw new RuntimeException('Не вдалося отримати Job ID від ЕСОЗ.');
        }
        $jobApi = EHealth::job();
        $attempts = 0;

        do {
            sleep(2);
            $finalResponse = $jobApi->getDetails($jobId)->getData();
            $attempts++;
            $status = strtolower((string) ($finalResponse['status'] ?? ''));
        } while (in_array($status, ['pending', 'accepted', 'processing'], true) && $attempts < 15);

        if ($status !== 'processed' && $status !== 'active') {
            $errorHandler = new ErrorHandler();
            $errorResult = $errorHandler->handleError($finalResponse);
            $errorMessages = $errorResult['errors'] ?? [];
            if (empty($errorMessages) || $errorMessages[0] === 'No valid error information provided.') {
                $fallbackMsg = data_get($finalResponse, 'error.message')
                    ?? data_get($finalResponse, 'message')
                    ?? 'Unknown eHealth Error';
                $errorMessages = [$fallbackMsg];
            }
            throw new RuntimeException(implode("\n", $errorMessages));
        }
        $syncData = EHealth::encounter()->getById($patientUuid, $encounterUuid)->validate();
        Repository::encounter()->sync($patientModel, [$syncData]);
    }
}
