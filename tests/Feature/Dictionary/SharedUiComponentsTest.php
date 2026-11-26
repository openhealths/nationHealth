<?php

declare(strict_types=1);

namespace Tests\Feature\Dictionary;

use App\Livewire\Components\Header;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\TestCase;

class SharedUiComponentsTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;

    public function test_drawer_headers_keep_distinct_profile_controls(): void
    {
        $this->withoutVite();
        $context = $this->compositionFixture();
        $this->instance('legalEntity', $context['legalEntity']);
        Gate::before(fn (): bool => true);
        $first = Livewire::test(Header::class)->html();
        $second = Livewire::test(Header::class)->html();
        $html = $first.$second;
        $drawer = Blade::render('<x-dialog-drawer :header="$header">Drawer</x-dialog-drawer>', ['header' => new \Illuminate\Support\HtmlString($html)]);
        $this->assertStringContainsString('data-dialog-header', $drawer);
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $this->assertCount(2, $xpath->query('//nav[@data-app-header]'));
        $buttons = $xpath->query('//button[starts-with(@id, "user-menu-button-")]');
        $this->assertCount(2, $buttons);
        $this->assertNotSame($buttons->item(0)->getAttribute('id'), $buttons->item(1)->getAttribute('id'));
    }
}
