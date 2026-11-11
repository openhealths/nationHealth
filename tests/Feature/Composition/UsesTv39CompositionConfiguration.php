<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

trait UsesTv39CompositionConfiguration
{
    protected function setUpUsesTv39CompositionConfiguration(): void
    {
        // Deliberate test values, never defaults for any deployment.
        config(['compositions.emal' => json_decode(file_get_contents(__DIR__.'/Fixtures/composition-emal.json'), true, flags: JSON_THROW_ON_ERROR)]);
    }
}
