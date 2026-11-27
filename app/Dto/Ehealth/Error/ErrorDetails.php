<?php

declare(strict_types=1);

namespace App\Dto\Ehealth\Error;

use App\Contracts\ApiErrorTranslator;
use Illuminate\Support\Arr;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Condition\TargetClass;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: ErrorResponseData::class)]
class ErrorDetails
{
    public ApiErrorTranslator $translator;

    #[Map(source: 'payload', if: new SourceClass(ErrorResponseData::class), transform: [self::class, 'requestMessage'])]
    public ?string $message = null;

    /**
     * @var list<ErrorMessageData>
     */
    #[Map(source: 'payload', if: new SourceClass(ErrorResponseData::class), transform: [self::class, 'fieldErrors'])]
    #[Map(target: 'messages', if: new TargetClass(Messages::class), transform: [[Messages::class, 'wrapMessages'], new MapCollection(targetClass: Message::class)])]
    public array $fields = [];

    public static function requestMessage(array $payload): ?string
    {
        return self::text(Arr::get($payload, 'error.message')) ?? self::text($payload['message'] ?? null);
    }

    /**
     * @return list<ErrorMessageData>
     */
    public static function fieldErrors(array $payload, ErrorResponseData $source): array
    {
        $invalid = Arr::get($payload, 'error.invalid') ?? $payload['invalid'] ?? [];
        $fields = [];

        foreach (\is_array($invalid) ? $invalid : [] as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $entry = self::text($item['entry'] ?? null) ?? self::text($item['param'] ?? null);
            $message = self::text($item['msg'] ?? null);
            $rules = \is_array($item['rules'] ?? null) ? array_filter($item['rules'], is_array(...)) : [];

            if ($rules === [] && $message !== null) {
                $rules = [['description' => $message]];
            }

            foreach ($rules as $rule) {
                $fields[] = new ErrorMessageData(
                    entry: $entry,
                    description: self::text($rule['description'] ?? null) ?? $message,
                    translator: $source->translator,
                    rule: self::text($rule['rule'] ?? null),
                    params: \is_array($rule['params'] ?? null) ? $rule['params'] : []
                );
            }
        }

        return $fields;
    }

    protected static function text(mixed $value): ?string
    {
        return \is_string($value) && trim($value) !== '' ? $value : null;
    }
}
