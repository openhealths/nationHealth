<?php

declare(strict_types=1);

namespace App\Exceptions\MedicalEvents;

use RuntimeException;

/**
 * A TV 3.8 rule that must hold before a conclusion request leaves the MIS.
 *
 * These rules are also expressed in the UI (hidden options, disabled buttons), but a
 * Livewire action is a public endpoint: anything the UI merely discourages can still be
 * invoked directly. Throwing from the action is what makes the rule actually binding,
 * and the message is already user-facing so it can be flashed as-is.
 */
class CompositionGuardException extends RuntimeException
{
}
