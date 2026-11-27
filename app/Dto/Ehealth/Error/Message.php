<?php

declare(strict_types=1);

namespace App\Dto\Ehealth\Error;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: ErrorMessageData::class)]
class Message
{
    #[Map(source: 'description', transform: [self::class, 'translate'])]
    public string $message;

    public static function translate(?string $description, ErrorMessageData $source): string
    {
        $description ??= __('errors.ehealth.messages.missing_error_description');

        $message = $source->translator->translate($description) ?? $description;

        if (!$source->isField) {
            return $message;
        }

        $entry = $source->entry ?? __('errors.ehealth.messages.unknown_field');
        $key = Str::camel(preg_replace(['/^\$\./', '/\[\d+]/'], ['', '.*'], $entry));

        $attribute = Arr::get(__('validation.attributes'), $key);

        $field = \is_string($attribute) ? $attribute : $entry;

        // Keep the original path to identify array items after the wildcard label lookup.
        if (\is_string($attribute) && preg_match('/\[\d+]/', $entry) === 1) {
            $field .= " ({$entry})";
        }

        return "{$field}: {$message}";
    }
}
