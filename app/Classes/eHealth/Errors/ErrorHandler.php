<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Errors;

class ErrorHandler
{
    public function handleError(array $error)
    {
        $errorMessages = [];

        if (isset($error['error']['invalid']) && is_array($error['error']['invalid'])) {
            foreach ($error['error']['invalid'] as $invalidEntry) {
                $entry = $invalidEntry['entry'] ?? 'Unknown';
                $entryType = $invalidEntry['entry_type'] ?? 'Unknown';
                $rules = $invalidEntry['rules'] ?? [];
                foreach ($rules as $rule) {
                    if (!isset($rule['description']) && isset($error['error']['message'])) {
                        continue;
                    }

                    $description = $rule['description'] ?? 'No description';
                    $errorMessages[] = $description === 'required property issued_by was not present'
                        ? __('validation.custom.employee.document_issuer_required')
                        : "Error in entry '{$entry}' (Type: {$entryType}): {$description}";
                }
            }
        }

        if ($errorMessages === []) {
            $message = $error['error']['message'] ?? 'No valid error information provided.';

            $errorMessages[] = $this->translateMessage($message);
        }

        return (['errors' => $errorMessages]);
    }

    private function translateMessage(string $message): string
    {
        $translations = [
            'Does not match the signer last name' => 'errors.ehealth.messages.signer_last_name_mismatch',
        ];

        return isset($translations[$message]) ? __($translations[$message]) : $message;
    }
}
