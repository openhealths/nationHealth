<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Models\CarePlan;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: CarePlan::class)]
final class ActivityRequirements
{
    #[Map(source: '[category?]', transform: [self::class, 'category'])]
    public ?string $category;

    #[Map(source: '[termsOfService?]', transform: [self::class, 'mapTermsOfService'])]
    public ?string $termsOfService;

    public static function category(mixed $category, CarePlan $carePlan): ?string
    {
        if (is_array($category)) {
            $category = $category['coding'][0]['code'] ?? ($category['text'] ?? null);
        }

        if (is_string($category) && trim($category) !== '') {
            return trim($category);
        }

        $conceptCode = ($carePlan->relationLoaded('categoryConcept') ? $carePlan->getRelation('categoryConcept')?->getRelation('coding')?->first()?->code : null);
        if (is_string($conceptCode) && trim($conceptCode) !== '') {
            return trim($conceptCode);
        }

        return null;
    }

    public static function mapTermsOfService(mixed $tos): ?string
    {
        if (is_array($tos)) {
            $tos = $tos['coding'][0]['code'] ?? ($tos['text'] ?? null);
        }

        if (!is_string($tos) || trim($tos) === '') {
            return null;
        }

        return trim($tos);
    }

    public static function allowedConditions(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        if (is_string($raw)) {
            $raw = [$raw];
        }

        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            } elseif (is_array($item)) {
                $code = $item['code'] ?? ($item['coding'][0]['code'] ?? null);
                if (is_string($code) && trim($code) !== '') {
                    $out[] = trim($code);
                }
            }
        }

        return array_values(array_unique($out));
    }
}
