<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Party;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PartyVerifyCommentLabelDarkThemeTest extends TestCase
{
    #[Test]
    public function comment_label_has_no_solid_white_background(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/party/party-verify.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringContainsString('dark:text-gray-400', $blade);
        $this->assertDoesNotMatchRegularExpression(
            '/label[^>]*for="comment"[^>]*bg-white/',
            $blade
        );
        $this->assertDoesNotMatchRegularExpression(
            '/for="comment"[^>]*class="[^"]*bg-white/',
            $blade
        );
    }
}
