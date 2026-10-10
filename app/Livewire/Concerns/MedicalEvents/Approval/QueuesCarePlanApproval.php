<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\Approval;

use App\Dto\MedicalEvents\CarePlanApprovalCreateResult;
use App\Enums\MedicalEvents\CarePlanApprovalCreateOutcome;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

trait QueuesCarePlanApproval
{
    protected function queueCarePlanApproval(
        CarePlan $carePlan,
        array $responseData,
        ?LegalEntity $legalEntity,
        ?User $user,
        ?string $bearerToken,
        ?string $grantedToEmployeeUuid = null,
    ): CarePlanApprovalCreateResult {
        $href = $responseData['links'][0]['href'] ?? null;

        if (!$href) {
            throw new RuntimeException('Async approval response missing job link href');
        }

        if (!$legalEntity) {
            throw new RuntimeException('Legal entity is required for async approval processing');
        }

        if (!$user) {
            throw new RuntimeException('User is required for async approval processing');
        }

        if ($bearerToken === null || $bearerToken === '') {
            throw new RuntimeException('Bearer token is required for async approval processing');
        }

        $approvalUuid = $responseData['id'] ?? (string) Str::uuid();

        $link = Repository::approval()->createPendingCarePlanApproval($carePlan, $approvalUuid, $grantedToEmployeeUuid, $href);
        $localApproval = $link->linkable;

        Bus::batch([
            new RemoteEHealthLinksProcessing(
                eHealthLink: $link,
                legalEntity: $legalEntity,
                standalone: true
            ),
        ])
            ->withOption('legal_entity_id', $legalEntity->id)
            ->withOption('token', Crypt::encryptString($bearerToken))
            ->withOption('user', $user)
            ->name(RemoteEHealthLinksProcessing::BATCH_NAME)
            ->onQueue('sync')
            ->dispatch();

        return new CarePlanApprovalCreateResult(
            CarePlanApprovalCreateOutcome::Async,
            $localApproval->uuid,
            $link->id,
        );
    }
}
