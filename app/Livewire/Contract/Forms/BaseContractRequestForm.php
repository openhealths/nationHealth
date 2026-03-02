<?php

declare(strict_types=1);

namespace App\Livewire\Contract\Forms;

use App\Core\Arr;
use App\Core\BaseForm;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

abstract class BaseContractRequestForm extends BaseForm
{
    public string $contractorLegalEntityId;

    public string $contractorOwnerId;

    public string $contractorBase;

    public string $idForm;

    public string $startDate;

    public string $endDate;

    public string $contractNumber = '';

    public $statuteMd5;

    public $additionalDocumentMd5;

    public array $contractorPaymentDetails;

    public string $knedp;

    public TemporaryUploadedFile $keyContainerUpload;

    public string $password;

    /**
     * Base rules for both types of contract
     *
     * @return array[]
     */
    public function rules(): array
    {
        $hasContractNumber = !empty($this->contractNumber);

        return [
            'contractorLegalEntityId' => ['required', 'uuid', 'exists:legal_entities,uuid'],
            'contractorOwnerId' => ['required', 'uuid', 'exists:employees,uuid'],
            'contractorBase' => ['required', 'string', 'max:255'],
            'startDate' => [
                'required',
                'date_format:d.m.Y',
                // the year in start_date must be equal to current or next year (current+1)
                function ($attribute, $value, $fail) {
                    $date = CarbonImmutable::parse($value);

                    if (!($date->isCurrentYear() || $date->isNextYear())) {
                        $fail('дата початку дії договору повинна бути рівною поточному або наступному року');
                    }
                }
            ],
            'endDate' => [
                'required',
                'date_format:d.m.Y',
                'after_or_equal:startDate',
            ],
            'contractorPaymentDetails' => ['required', 'array'],
            'contractorPaymentDetails.payerAccount' => ['required', 'string', 'max:255'],
            'contractorPaymentDetails.MFO' => [
                'required_unless:contractorPaymentDetails.payerAccount,/^UA[0-9]{22}$/,/^UA[0-9]{27}$/',
                'string',
                'max:255'
            ],
            'contractorPaymentDetails.bankName' => ['required', 'string', 'max:255'],
            'contractNumber' => ['nullable', 'string', 'max:255'],
            'statuteMd5' => ['nullable', 'file'],
            'additionalDocumentMd5' => ['nullable', 'file'],
        ];
    }

    public function formatForApi(array $data): array
    {
        collect($data)
            ->only(['startDate', 'endDate'])
            ->filter()
            ->each(static function (string $value, string $key) use (&$data) {
                $data[$key] = convertToYmd($value);
            });

        return removeEmptyKeys(Arr::toSnakeCase($data));
    }
}
