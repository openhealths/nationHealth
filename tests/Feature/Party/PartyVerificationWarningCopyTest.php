<?php

declare(strict_types=1);

namespace Tests\Feature\Party;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies party verification warning copy matches conclusion 3.23 п.3.2.2.
 */
class PartyVerificationWarningCopyTest extends TestCase
{
    #[Test]
    public function warning_translations_contain_required_fragments_from_conclusion_3_23(): void
    {
        $header = __('party_verification.warning.header');
        $drfo = __('party_verification.warning.drfo');
        $dracsDeath = __('party_verification.warning.dracs_death');
        $dmsPassport = __('party_verification.warning.dms_passport');
        $footer = __('party_verification.warning.footer');

        $this->assertSame(
            'Увага! Персональні дані працівника потребують перевірки:',
            $header
        );

        $this->assertStringContainsString('реєстрі ДПС', $drfo);
        $this->assertStringContainsString('відмови працівника від присвоєння РНОКПП', $drfo);
        $this->assertStringContainsString('оновити відомості про документ', $drfo);

        $this->assertStringContainsString('актовий запис про смерть', $dracsDeath);
        $this->assertStringContainsString('Міністерства юстиції', $dracsDeath);
        $this->assertStringContainsString('підтвердження чи спростування', $dracsDeath);
        $this->assertStringContainsString('відділу кадрів або керівника закладу', $dracsDeath);

        $this->assertStringContainsString('Зазначений паспорт працівника не дійсний за даними ДМС', $dmsPassport);
        $this->assertStringContainsString('оригіналом документу', $dmsPassport);
        $this->assertStringContainsString('звернутись до ДМС', $dmsPassport);

        $this->assertStringContainsString('звернутись до НСЗУ', $footer);
        $this->assertStringContainsString('ДПС, ДРАЦСГ або ДМС', $footer);
        $this->assertStringContainsString('залежно від отриманого статусу', $footer);
        $this->assertStringContainsString('Якщо оновлення не можливе', $footer);

        $this->assertSame($drfo, __('party_verification.recommendations.drfo'));
        $this->assertSame($dracsDeath, __('party_verification.recommendations.dracs_death'));
        $this->assertSame($dmsPassport, __('party_verification.recommendations.dms_passport'));
    }

    #[Test]
    public function update_flash_messages_are_translated_in_ukrainian(): void
    {
        $this->assertSame(
            'Статус верифікації працівника успішно оновлено.',
            __('party_verification.messages.update_success')
        );
        $this->assertStringContainsString(
            'Не вдалося оновити статус верифікації в ЕСОЗ',
            __('party_verification.messages.update_failed')
        );
        $this->assertStringContainsString(
            'технічна помилка',
            __('party_verification.messages.update_failed_technical')
        );
        $this->assertStringContainsString(
            'не потребує ручного підтвердження',
            __('party_verification.update_unavailable_reason')
        );
        $this->assertSame('Дані успішно збережено', __('forms.data_saved_successfully'));
    }
}
