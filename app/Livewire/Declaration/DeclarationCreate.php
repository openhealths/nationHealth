<?php

declare(strict_types=1);

namespace App\Livewire\Declaration;

use App\Core\Arr;
use App\Enums\Declaration\Status;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use Exception;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

class DeclarationCreate extends DeclarationComponent
{
    public function mount(LegalEntity $legalEntity, int $personId): void
    {
        $this->baseMount($personId);
    }

    public function createLocally(): void
    {
        if (!$this->ensureAbility('create', __('declarations.policy.create'))) {
            return;
        }

        $this->setDivisionId();

        try {
            $validated = $this->form->validate($this->form->rulesForCreating());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $validated['status'] = Status::DRAFT->value;

            Repository::declarationRequest()->store(Arr::toSnakeCase($validated));

            $this->redirectRoute('declaration.index', [legalEntity()], navigate: true);
        } catch (Exception $exception) {
            $this->handleDatabaseErrors($exception, 'Error saving declaration request');

            return;
        }
    }
}
