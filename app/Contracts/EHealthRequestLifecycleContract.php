<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * The eHealth request lifecycle shared by medication and device requests: check a medical
 * program, create a draft, then sign or reject that draft by id.
 *
 * Callers that already hold an eHealth-shaped payload depend on this contract only; the
 * richer care plan and encounter flows stay on the concrete services because they also
 * persist local records and resolve context.
 *
 * @see \App\Livewire\MedicationRequest\MedicationRequestForm
 * @see \App\Livewire\DeviceRequest\DeviceRequestForm
 */
interface EHealthRequestLifecycleContract
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preQualify(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createDraft(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload  Must carry the KEP-signed content
     * @return array<string, mixed>
     */
    public function sign(string $id, array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function reject(string $id, array $payload): array;
}
