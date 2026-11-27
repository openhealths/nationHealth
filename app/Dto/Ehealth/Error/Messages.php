<?php

declare(strict_types=1);

namespace App\Dto\Ehealth\Error;

class Messages
{
    /**
     * @var list<Message>
     */
    public array $messages = [];

    /**
     * @param  list<ErrorMessageData>  $fields
     * @return non-empty-list<ErrorMessageData>
     */
    public static function wrapMessages(array $fields, ErrorDetails $source): array
    {
        if ($source->message !== null) {
            $fields = array_values(array_filter($fields, static fn (ErrorMessageData $field): bool => $field->description !== null));
        }

        if ($fields !== []) {
            return $fields;
        }

        return [new ErrorMessageData(
            entry: null,
            description: $source->message ?? __('errors.ehealth.messages.request_error'),
            translator: $source->translator,
            isField: false
        )];
    }

    /**
     * @return list<string>
     */
    public function toArray(): array
    {
        return array_map(static fn (Message $message): string => $message->message, $this->messages);
    }
}
