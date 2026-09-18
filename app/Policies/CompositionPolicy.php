<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Person\CompositionType;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Authorisation for Medical Conclusions (МВН / МВТН).
 *
 * Every action is gated by the matching `composition:*` scope, and creation additionally
 * by the legal entity type and employee role *as a pair* — TV 3.8.1.1 and 3.8.2.1 name
 * combinations, not two separate conditions:
 *
 *   - МВН (NEWBORN)          — OUTPATIENT + SPECIALIST.
 *   - МВТН (TEMP_DISABILITY) — PRIMARY_CARE + DOCTOR, or OUTPATIENT + SPECIALIST.
 *
 * eHealth enforces the same rules again on its side.
 */
class CompositionPolicy
{
    /**
     * Value of COMPOSITION_PROCESSING_STATUS that marks a failed integration.
     */
    private const string ERLN_STATUS_ERROR = 'ERROR';

    public function viewAny(User $user): Response
    {
        return $user->can('composition:search') && $this->inConclusionIssuingEntity()
            ? Response::allow()
            : Response::deny(__('compositions.errors.view_not_allowed'));
    }

    public function view(User $user, Composition $composition): Response
    {
        if (!$user->can('composition:read') || !$this->inConclusionIssuingEntity()) {
            return Response::deny(__('compositions.errors.view_not_allowed'));
        }

        return Response::allow();
    }

    /**
     * Create a birth conclusion — OUTPATIENT + SPECIALIST (TV 3.8.1.1).
     */
    public function createNewborn(User $user): Response
    {
        return $this->mayCreate(
            $user,
            CompositionType::NEWBORN,
            __('compositions.errors.create_newborn_not_allowed')
        );
    }

    /**
     * Create a temporary disability conclusion (TV 3.8.2.1).
     *
     * PRIMARY_CARE + DOCTOR, or OUTPATIENT + SPECIALIST.
     */
    public function createTempDisability(User $user): Response
    {
        return $this->mayCreate(
            $user,
            CompositionType::TEMP_DISABILITY,
            __('compositions.errors.create_temp_disability_not_allowed')
        );
    }

    /**
     * The scope, the legal entity type and the employee role, checked as one rule.
     *
     * Checking the entity type on its own is what allowed an outpatient DOCTOR to reach
     * the birth conclusion flow: the pair is the requirement, so the pair is the check.
     */
    private function mayCreate(User $user, CompositionType $type, string $denial): Response
    {
        if (!$user->can('composition:create')) {
            return Response::deny($denial);
        }

        if ($type->allowedAuthorRoles($this->legalEntityType()) === []) {
            return Response::deny($denial);
        }

        return $user->getCompositionAuthorEmployee($type) !== null
            ? Response::allow()
            : Response::deny($denial);
    }

    /**
     * Sign an unsigned conclusion (TV 3.8.1.7, 3.8.2.9).
     *
     * Signing is what turns a draft into a legal document, so it is restricted to the
     * author rather than to anyone holding the scope.
     */
    public function sign(User $user, Composition $composition): Response
    {
        if (!$user->can('composition:sign')) {
            return Response::deny(__('compositions.errors.sign_not_allowed'));
        }

        if (!$composition->status->isSignable()) {
            return Response::deny(__('compositions.errors.sign_status_not_preliminary'));
        }

        return $this->isAuthor($user, $composition)
            ? Response::allow()
            : Response::deny(__('compositions.errors.sign_not_author'));
    }

    /**
     * Mark a conclusion as entered in error (TV 3.8.1.10.1, 3.8.2.15.1).
     *
     * The remaining precondition from the requirements — that no integration process has
     * started — cannot be decided here because it needs a getIntegrationData call.
     */
    public function cancel(User $user, Composition $composition): Response
    {
        if (!$user->can('composition:cancel')) {
            return Response::deny(__('compositions.errors.cancel_not_allowed'));
        }

        if (!$composition->status->isCancellable()) {
            return Response::deny(__('compositions.errors.cancel_status_not_final'));
        }

        return $this->isAuthor($user, $composition)
            ? Response::allow()
            : Response::deny(__('compositions.errors.cancel_not_author'));
    }

    /**
     * Retry ERLN registration for a temporary disability conclusion (TV 3.8.2.14.1).
     */
    public function resendErln(User $user, Composition $composition): Response
    {
        // Retrying is a PATCH on an existing conclusion, so it is gated by the write
        // scope rather than by the one that allows a brand new conclusion to be issued.
        if (!$user->can('composition:write')) {
            return Response::deny(__('compositions.errors.erln_resend_not_allowed'));
        }

        if ($composition->type !== CompositionType::TEMP_DISABILITY) {
            return Response::deny(__('compositions.errors.erln_resend_not_applicable'));
        }

        if (!$composition->status->isCancellable()) {
            return Response::deny(__('compositions.errors.erln_resend_not_final'));
        }

        return $composition->erlnStatus === self::ERLN_STATUS_ERROR
            ? Response::allow()
            : Response::deny(__('compositions.errors.erln_resend_not_error'));
    }

    /**
     * Whether the current legal entity may issue conclusions at all.
     */
    private function inConclusionIssuingEntity(): bool
    {
        return in_array(
            $this->legalEntityType(),
            [LegalEntity::TYPE_PRIMARY_CARE, LegalEntity::TYPE_OUTPATIENT],
            true
        );
    }

    private function legalEntityType(): ?string
    {
        return legalEntity()?->type?->name;
    }

    private function isAuthor(User $user, Composition $composition): bool
    {
        $authorUuid = $composition->authorUuid;

        return filled($authorUuid)
            && in_array($authorUuid, $user->getCompositionEmployeeUuids(), true);
    }
}
