<?php

declare(strict_types=1);

namespace Tests\Unit\eHealth;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\Errors\ErrorHandler;
use App\Classes\eHealth\Exceptions\ApiException;
use App\Contracts\ApiErrorTranslator;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\LegalEntity\Forms\LegalEntitiesRequestApi;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegalEntityErrorTranslationTest extends TestCase
{
    #[DataProvider('drfoErrors')]
    public function test_validation_exception_displays_every_drfo_rule(array $invalid): void
    {
        $exception = new EHealthValidationException(['error' => [
            'type' => 'validation_failed',
            'invalid' => $invalid,
        ]]);

        foreach ([$exception->getFormattedMessage(), $exception->getTranslatedMessage()] as $message) {
            $this->assertStringContainsString('Номер РНОКПП: ' . __('errors.ehealth.messages.signer_drfo_mismatch'), $message);
            $this->assertStringContainsString('Номер РНОКПП: ' . __('errors.ehealth.messages.invalid_format'), $message);
        }
    }

    #[DataProvider('drfoErrors')]
    public function test_legal_entity_request_translates_every_drfo_rule(array $invalid): void
    {
        config(['ehealth.api.domain' => 'https://ehealth.example']);
        Http::preventStrayRequests();
        Http::fake([
            'https://ehealth.example/api/v2/legal_entities' => Http::response([
                'error' => ['type' => 'validation_failed', 'invalid' => $invalid],
            ], 422),
        ]);

        try {
            LegalEntitiesRequestApi::_createOrUpdate([
                'signed_legal_entity_request' => 'synthetic-signed-content',
                'signed_content_encoding' => 'base64',
            ]);
            $this->fail('The rejected legal entity request must throw an API exception.');
        } catch (ApiException $exception) {
            $this->assertSame(422, $exception->getCode());
            $this->assertSame(
                'Номер РНОКПП: РНОКПП користувача не співпадає з РНОКПП, зазначеним у КЕП'
                    . ' | Номер РНОКПП: Некоректний формат значення.',
                $exception->getMessage()
            );
        }

        Http::assertSentCount(1);
    }

    public static function drfoErrors(): array
    {
        $mismatch = ['description' => 'Does not match the signer drfo'];
        $format = ['description' => 'has invalid format'];

        return [
            'multiple rules for one entry' => [[[
                'entry' => '$.drfo',
                'entry_type' => 'json_data_property',
                'rules' => [$mismatch, $format],
            ]]],
            'separate entries for the same field' => [[
                ['entry' => '$.drfo', 'entry_type' => 'json_data_property', 'rules' => [$mismatch]],
                ['entry' => '$.drfo', 'entry_type' => 'json_data_property', 'rules' => [$format]],
            ]],
        ];
    }

    #[DataProvider('topLevelMessages')]
    public function test_top_level_messages_use_the_shared_translator(string $message, string $expected): void
    {
        $result = (new ErrorHandler())->handleError(['error' => [
            'message' => $message,
            'invalid' => [['rules' => [['rule' => 'invalid']]]],
        ]]);

        $this->assertSame([$expected], $result);
    }

    public static function topLevelMessages(): array
    {
        return [
            ['Does not match the signer drfo', 'РНОКПП користувача не співпадає з РНОКПП, зазначеним у КЕП'],
            ['Does not match the signer last name', 'Прізвище не збігається з прізвищем підписанта.'],
            ['has invalid format', 'Некоректний формат значення.'],
            ['Unknown API message', 'Unknown API message'],
        ];
    }

    public function test_unknown_field_rules_keep_the_original_reason(): void
    {
        $result = (new ErrorHandler())->handleError(['error' => ['invalid' => [[
            'entry' => '$.future_field',
            'rules' => [['description' => 'Future validation rule']],
        ]]]]);

        $this->assertSame(['$.future_field: Future validation rule'], $result);
    }

    public function test_translated_array_fields_keep_their_original_indices(): void
    {
        $result = (new ErrorHandler())->handleError(['error' => ['invalid' => [
            [
                'entry' => '$.owner.phones[0].number',
                'rules' => [['description' => 'has invalid format']],
            ],
            [
                'entry' => '$.owner.phones[1].number',
                'rules' => [['description' => 'has invalid format']],
            ],
        ]]]);

        $this->assertSame([
            'телефон ($.owner.phones[0].number): Некоректний формат значення.',
            'телефон ($.owner.phones[1].number): Некоректний формат значення.',
        ], $result);
    }

    public function test_unknown_array_fields_keep_the_original_path(): void
    {
        $result = (new ErrorHandler())->handleError(['error' => ['invalid' => [[
            'entry' => '$.future_fields[2].number',
            'rules' => [['description' => 'Future validation rule']],
        ]]]]);

        $this->assertSame(['$.future_fields[2].number: Future validation rule'], $result);
    }

    public function test_missing_error_details_have_ukrainian_fallbacks(): void
    {
        $handler = new ErrorHandler();

        $this->assertSame(['Помилка в процесі обробки запиту'], $handler->handleError([]));
        $this->assertSame(
            ['Невідоме поле: Опис помилки відсутній'],
            $handler->handleError(['error' => ['invalid' => [['rules' => [[]]]]]])
        );
    }

    public function test_resource_translator_is_used_for_top_level_and_field_errors(): void
    {
        $translator = new class implements ApiErrorTranslator
        {
            public function translate(string $message): ?string
            {
                return $message === 'Resource-specific error' ? 'Помилка обраного ресурсу' : null;
            }
        };
        $handler = EHealth::errorHandler($translator);

        $this->assertSame(
            ['Помилка обраного ресурсу'],
            $handler->handleError(['error' => ['message' => 'Resource-specific error']])
        );
        $this->assertSame(
            ['Номер РНОКПП: Помилка обраного ресурсу'],
            $handler->handleError(['error' => ['invalid' => [[
                'entry' => '$.drfo',
                'rules' => [['description' => 'Resource-specific error']],
            ]]]])
        );
    }

    public function test_nested_fields_use_existing_validation_labels(): void
    {
        $result = new ErrorHandler()->handleError(['error' => ['invalid' => [[
            'entry' => '$.owner.tax_id',
            'rules' => [['description' => 'has invalid format']],
        ]]]]);

        $this->assertSame(['РНОКПП: Некоректний формат значення.'], $result);
    }
}
