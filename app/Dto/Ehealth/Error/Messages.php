<?php

declare(strict_types=1);

namespace App\Dto\Ehealth\Error;

use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: ErrorResponseData::class)]
class Messages
{
    /**
     * @var list<Message>
     */
    #[Map(source: 'payload', transform: [[self::class, 'wrapMessages'], new MapCollection(targetClass: Message::class)])]
    public array $messages = [];

    /**
     * Flatten eHealth validation rules into typed sources that MapCollection can map to Message DTOs.
     * Each rule keeps its field and the originating API translator so every reason can be translated separately.
     * When no rule entries are produced, wrap the top-level message or a default error to preserve user feedback.
     * Ignore malformed rows and treat non-string text as missing before constructing typed message sources.
     *
     * @param  array<string, mixed>  $payload  Original eHealth error response.
     * @param  ErrorResponseData  $source  Mapping context containing the originating API translator.
     * @return list<ErrorMessageData>
     */
    public static function wrapMessages(array $payload, ErrorResponseData $source): array
    {
        $error = \is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $topLevelMessage = \is_string($error['message'] ?? null) ? $error['message'] : null;
        $messages = [];

        if (isset($error['invalid']) && \is_array($error['invalid'])) {
            foreach ($error['invalid'] as $invalidEntry) {
                if (!\is_array($invalidEntry) || !\is_array($invalidEntry['rules'] ?? null)) {
                    continue;
                }

                $entry = \is_string($invalidEntry['entry'] ?? null)
                    ? $invalidEntry['entry']
                    : __('errors.ehealth.messages.unknown_field');

                foreach ($invalidEntry['rules'] as $rule) {
                    if (!\is_array($rule)) {
                        continue;
                    }

                    $description = \is_string($rule['description'] ?? null) ? $rule['description'] : null;

                    if ($description === null && $topLevelMessage !== null) {
                        continue;
                    }

                    $messages[] = new ErrorMessageData(
                        entry: $entry,
                        description: $description,
                        translator: $source->translator
                    );
                }
            }
        }

        if ($messages === []) {
            $messages[] = new ErrorMessageData(
                entry: null,
                description: $topLevelMessage ?? __('errors.ehealth.messages.request_error'),
                translator: $source->translator
            );
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    public function toArray(): array
    {
        return array_map(static fn (Message $message): string => $message->message, $this->messages);
    }
}
