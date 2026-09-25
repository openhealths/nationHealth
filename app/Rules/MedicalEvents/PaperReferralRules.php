<?php

declare(strict_types=1);

namespace App\Rules\MedicalEvents;

use Illuminate\Validation\Rule;

/**
 * Rules for the paper referral a record of the encounter package is based on.
 */
class PaperReferralRules
{
    /**
     * Rules for the paper referral fields of the records under the given prefix.
     *
     * @param  string  $prefix  e.g. 'procedures.*'
     * @param  array  $records  The validated records, indexed the same way as the validated attributes
     * @return array
     */
    public static function for(string $prefix, array $records): array
    {
        return [
            "$prefix.paperReferralRequisition" => ['nullable', 'string', 'max:255'],
            "$prefix.paperReferralRequesterEmployeeName" => Rule::forEach(
                static fn (mixed $value, string $attribute): array => [
                    ...self::presence($records, $attribute),
                    'string',
                    'max:255'
                ]
            ),
            "$prefix.paperReferralRequesterLegalEntityEdrpou" => Rule::forEach(
                static fn (mixed $value, string $attribute): array => [
                    ...self::presence($records, $attribute),
                    'digits_between:8,10'
                ]
            ),
            "$prefix.paperReferralRequesterLegalEntityName" => Rule::forEach(
                static fn (mixed $value, string $attribute): array => [
                    Rule::prohibitedIf(self::referralTypeOf($records, $attribute) === 'electronic'),
                    'nullable',
                    'string',
                    'max:255'
                ]
            ),
            "$prefix.paperReferralServiceRequestDate" => Rule::forEach(
                static fn (mixed $value, string $attribute): array => [
                    ...self::presence($records, $attribute),
                    'date_format:' . config('app.date_format')
                ]
            ),
            "$prefix.paperReferralNote" => ['nullable', 'string']
        ];
    }

    /**
     * Presence rules for a paper referral field the eHealth schema lists as required: it has to be filled in
     * for a paper referral and cannot be filled in for an electronic one.
     *
     * @param  array  $records
     * @param  string  $attribute
     * @return array
     */
    private static function presence(array $records, string $attribute): array
    {
        $referralType = self::referralTypeOf($records, $attribute);

        return [
            Rule::requiredIf($referralType === 'paper'),
            Rule::prohibitedIf($referralType === 'electronic'),
            'nullable'
        ];
    }

    /**
     * Referral type of the record the validated attribute belongs to.
     *
     * @param  array  $records
     * @param  string  $attribute
     * @return string
     */
    private static function referralTypeOf(array $records, string $attribute): string
    {
        $index = (int) explode('.', $attribute)[1];

        return (string) ($records[$index]['referralType'] ?? '');
    }
}
