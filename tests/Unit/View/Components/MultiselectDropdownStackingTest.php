<?php

declare(strict_types=1);

namespace Tests\Unit\View\Components;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MultiselectDropdownStackingTest extends TestCase
{
    #[Test]
    public function multiselect_elevates_form_group_when_open(): void
    {
        $blade = file_get_contents(resource_path('views/components/forms/multiselect.blade.php'));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertNotFalse($blade);
        $this->assertNotFalse($css);
        $this->assertStringContainsString("group.style.zIndex = open ? '50' : ''", $blade);
        $this->assertStringContainsString('!bg-white', $blade);
        $this->assertStringContainsString(
            '.form-group:has(.multiselect-dropdown:not([style*="display: none"]))',
            $css
        );
        $this->assertStringContainsString('background-color: #ffffff !important', $css);
    }
}
