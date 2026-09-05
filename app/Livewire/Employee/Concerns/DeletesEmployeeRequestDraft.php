<?php

declare(strict_types=1);

namespace App\Livewire\Employee\Concerns;

use App\Models\Employee\EmployeeRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Shared draft-delete modal actions for employee / employee-request indexes.
 * Only local drafts (no eHealth UUID) may be deleted.
 */
trait DeletesEmployeeRequestDraft
{
    public bool $showDeleteModal = false;

    public ?int $requestToDeleteId = null;

    public ?string $deleteRequestName = null;

    public function confirmRequestDeletion(int $id): void
    {
        $request = EmployeeRequest::with('party')
            ->where('legal_entity_id', legalEntity()->id)
            ->find($id);

        if (!$request || !$request->isLocalDraft()) {
            return;
        }

        if (Auth::user()->cannot('delete', $request)) {
            $this->dispatch('flashMessage', [
                'message' => __('employees.policy.req.delete_denied'),
                'type' => 'error',
            ]);

            return;
        }

        $this->requestToDeleteId = $id;
        $this->deleteRequestName = $request->party?->fullName
            ?? __('employees.modals.delete_draft.default_name');
        $this->showDeleteModal = true;
    }

    public function deleteRequest(): void
    {
        if (!$this->requestToDeleteId) {
            return;
        }

        $request = EmployeeRequest::with('revision')
            ->where('legal_entity_id', legalEntity()->id)
            ->find($this->requestToDeleteId);

        if ($request && $request->isLocalDraft() && Auth::user()->can('delete', $request)) {
            if ($request->revision) {
                $request->revision->forceDelete();
            }

            $request->delete();

            $this->dispatch(
                'flashMessage',
                ['message' => __('employees.draft.delete_success'), 'type' => 'success']
            );
        }

        $this->closeDeleteModal();
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->requestToDeleteId = null;
        $this->deleteRequestName = null;
    }
}
