<?php

declare(strict_types=1);

namespace App\Enums\Contract;

use App\Traits\EnumUtils;

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

    public function label(?Type $contractType = null): string
    {
        return match ($this) {
            self::GENERAL => __('contracts.id_form.GENERAL'),
            self::INSULIN_1 => __('contracts.id_form.INSULIN_1'),
            self::ND_1 => __('contracts.id_form.ND_1'),
            self::PSYCHIATRY => __('contracts.id_form.PSYCHIATRY'),
            self::PMD_1 => $contractType === Type::CAPITATION
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
        $dictionaryName = $type === Type::REIMBURSEMENT
            ? 'REIMBURSEMENT_CONTRACT_TYPE'
            : 'CONTRACT_TYPE';

        try {
            $label = dictionary()->basics()->byName($dictionaryName)->asCodeDescription()->get($code);

            if (is_string($label) && $label !== '') {
                return $label;
            }
        } catch (\Throwable) {
            // Fall back to local enum labels when the dictionary cache is unavailable.
        }

        $idForm = self::tryFrom($code);

        return $idForm?->label($type) ?? $code;
    }

    private static function normalizeType(mixed $contractType): ?Type
    {
        if ($contractType instanceof Type) {
            return $contractType;
        }

        $value = strtoupper((string) (is_object($contractType) && property_exists($contractType, 'value')
            ? $contractType->value
            : $contractType));

        return Type::tryFrom($value);
    }
}
