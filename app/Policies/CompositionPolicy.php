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
 * Two independent conditions gate every action, and eHealth enforces both again on its
 * side: the caller must hold the matching `composition:*` scope, and the legal entity
 * they are acting in must be of a type allowed to issue that kind of conclusion
 * (TV 3.8.1.1, 3.8.2.1):
 *
 *   - МВН (NEWBORN)          — OUTPATIENT only.
 *   - МВТН (TEMP_DISABILITY) — PRIMARY_CARE or OUTPATIENT.
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
            : Response::deny(__('patients.composition.errors.view_not_allowed'));
    }

    public function view(User $user, Composition $composition): Response
    {
        if (!$user->can('composition:read') || !$this->inConclusionIssuingEntity()) {
            return Response::deny(__('patients.composition.errors.view_not_allowed'));
        }

        return Response::allow();
    }

    /**
     * Create a birth conclusion — OUTPATIENT only (TV 3.8.1.1).
     */
    public function createNewborn(User $user): Response
    {
        if (!$user->can('composition:create')) {
            return Response::deny(__('patients.composition.errors.create_newborn_not_allowed'));
        }

        return $this->legalEntityType() === LegalEntity::TYPE_OUTPATIENT
            ? Response::allow()
            : Response::deny(__('patients.composition.errors.create_newborn_not_allowed'));
    }

    /**
     * Create a temporary disability conclusion — PRIMARY_CARE or OUTPATIENT (TV 3.8.2.1).
     */
    public function createTempDisability(User $user): Response
    {
        if (!$user->can('composition:create')) {
            return Response::deny(__('patients.composition.errors.create_temp_disability_not_allowed'));
        }

        return $this->inConclusionIssuingEntity()
            ? Response::allow()
            : Response::deny(__('patients.composition.errors.create_temp_disability_not_allowed'));
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
            return Response::deny(__('patients.composition.errors.sign_not_allowed'));
        }

        if (!$composition->status->isSignable()) {
            return Response::deny(__('patients.composition.errors.sign_status_not_preliminary'));
        }

        return $this->isAuthor($user, $composition)
            ? Response::allow()
            : Response::deny(__('patients.composition.errors.sign_not_author'));
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
            return Response::deny(__('patients.composition.errors.cancel_not_allowed'));
        }

        if (!$composition->status->isCancellable()) {
            return Response::deny(__('patients.composition.errors.cancel_status_not_final'));
        }

        return $this->isAuthor($user, $composition)
            ? Response::allow()
            : Response::deny(__('patients.composition.errors.cancel_not_author'));
    }

    /**
     * Retry ERLN registration for a temporary disability conclusion (TV 3.8.2.14.1).
     */
    public function resendErln(User $user, Composition $composition): Response
    {
        if (!$user->can('composition:create')) {
            return Response::deny(__('patients.composition.errors.erln_resend_not_allowed'));
        }

        if ($composition->type !== CompositionType::TEMP_DISABILITY) {
            return Response::deny(__('patients.composition.errors.erln_resend_not_applicable'));
        }

        if (!$composition->status->isCancellable()) {
            return Response::deny(__('patients.composition.errors.erln_resend_not_final'));
        }

        return $composition->erlnStatus === self::ERLN_STATUS_ERROR
            ? Response::allow()
            : Response::deny(__('patients.composition.errors.erln_resend_not_error'));
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
            && $user->getCompositionAuthorEmployee()?->uuid === $authorUuid;
    }
}
