<?php

declare(strict_types=1);

namespace App\Enums\Contract;

use App\Enums\Contract\Type as ContractType;
use App\Services\Dictionary\Collections\BasicDictionaryCollection;
use App\Services\Dictionary\Dictionaries\BasicDictionary;
use App\Traits\EnumUtils;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * eHealth id_form dictionary codes (CONTRACT_TYPE / REIMBURSEMENT_CONTRACT_TYPE).
 *
 * @see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18426757215/REIMBURSEMENT_CONTRACT_TYPE
 * @see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18068504822/CONTRACT_TYPE
 */
enum IdForm: string
{
    use EnumUtils;

    case GENERAL = 'GENERAL';
    case INSULIN_1 = 'INSULIN_1';
    case ND_1 = 'ND_1';
    case PMD_1 = 'PMD_1';
    case PSYCHIATRY = 'PSYCHIATRY';

    public function label(?ContractType $contractType = null): string
    {
        return match ($this) {
            self::GENERAL => __('contracts.id_form.GENERAL'),
            self::INSULIN_1 => __('contracts.id_form.INSULIN_1'),
            self::ND_1 => __('contracts.id_form.ND_1'),
            self::PSYCHIATRY => __('contracts.id_form.PSYCHIATRY'),
            self::PMD_1 => $contractType === ContractType::CAPITATION
                ? __('contracts.id_form.PMD_1_CAPITATION')
                : __('contracts.id_form.PMD_1'),
        };
    }

    /**
     * Resolve a display label for an arbitrary id_form code from API/DB.
     * Prefers eHealth dictionary when available, then enum labels.
     */
    public static function resolveLabel(?string $code, mixed $contractType = null): ?string
    {
        if (!is_string($code) || $code === '') {
            return null;
        }

        $type = self::normalizeType($contractType);
        $dictionaryName = $type === ContractType::REIMBURSEMENT
            ? 'REIMBURSEMENT_CONTRACT_TYPE'
            : 'CONTRACT_TYPE';

        try {
            // Rendering a stored contract must work while ESOZ is unavailable.
            $label = BasicDictionaryCollection::make(Cache::get(BasicDictionary::KEY, []))
                ->byName($dictionaryName, false)->asCodeDescription()->get($code);

            if (is_string($label) && $label !== '') {
                return $label;
            }
        } catch (Throwable) {
            // Fall back to local enum labels when the dictionary cache is unavailable.
        }

        $idForm = self::tryFrom($code);

        return $idForm?->label($type) ?? $code;
    }

    private static function normalizeType(mixed $contractType): ?ContractType
    {
        if ($contractType instanceof ContractType) {
            return $contractType;
        }

        $value = strtoupper((string) (is_object($contractType) && property_exists($contractType, 'value')
            ? $contractType->value
            : $contractType));

        return ContractType::tryFrom($value);
    }
}
