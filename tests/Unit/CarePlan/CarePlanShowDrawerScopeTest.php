<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use PHPUnit\Framework\TestCase;

/**
 * The care plan drawers (service / medication / medical device) toggle their
 * visibility through Alpine `x-show` bound to state declared on the
 * `shift-content` root. A single unbalanced `</div>` closes that root early,
 * which silently pushes the drawer includes out of the Alpine scope: the
 * Livewire round trip still runs, but no drawer ever opens or closes.
 */
class CarePlanShowDrawerScopeTest extends TestCase
{
    private const VIEW = 'resources/views/livewire/care-plan/care-plan-show.blade.php';

    public function test_div_tags_are_balanced(): void
    {
        $this->assertSame(0, $this->depthAtLine(null), 'Unbalanced <div> tags in '.self::VIEW);
    }

    public function test_drawer_includes_stay_inside_the_alpine_root(): void
    {
        $drawers = [
            'services-drawer',
            'service-search-drawer',
            'medications-drawer',
            'medication-search-drawer',
            'medication-form-drawer',
            'medical-devices-drawer',
            'medical-device-search-drawer',
            'medical-device-form-drawer',
        ];

        $depths = $this->includeDepths();

        foreach ($drawers as $drawer) {
            $this->assertArrayHasKey($drawer, $depths, "Drawer include [$drawer] is missing from ".self::VIEW);
            $this->assertSame(
                1,
                $depths[$drawer],
                "Drawer include [$drawer] must sit at depth 1, directly inside the shift-content Alpine root."
            );
        }
    }

    /**
     * @return array<string, int>
     */
    private function includeDepths(): array
    {
        $depths = [];
        $depth = 0;

        foreach ($this->lines() as $line) {
            $depth += $this->netDivs($line);

            if (preg_match("/@include\('livewire\.care-plan\.parts\.modals\.([a-z0-9-]+)'/", $line, $matches) === 1) {
                $depths[$matches[1]] = $depth;
            }
        }

        return $depths;
    }

    private function depthAtLine(?int $stopAt): int
    {
        $depth = 0;

        foreach ($this->lines() as $number => $line) {
            if ($stopAt !== null && $number > $stopAt) {
                break;
            }

            $depth += $this->netDivs($line);
        }

        return $depth;
    }

    private function netDivs(string $line): int
    {
        $line = preg_replace('/\{\{--.*?--\}\}/', '', $line) ?? $line;

        return preg_match_all('/<div\b/', $line) - preg_match_all('/<\/div>/', $line);
    }

    /**
     * @return array<int, string>
     */
    private function lines(): array
    {
        $path = dirname(__DIR__, 3).'/'.self::VIEW;
        $contents = file_get_contents($path);

        $this->assertNotFalse($contents, "Unable to read ".self::VIEW);

        return explode("\n", $contents);
    }
}
