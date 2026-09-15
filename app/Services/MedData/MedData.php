<?php

declare(strict_types=1);

namespace App\Services\MedData;

final class MedData
{
    /**
     * Get the vaccine lot service.
     *
     * @return VaccineLot
     */
    public static function vaccineLot(): VaccineLot
    {
        return app(VaccineLot::class);
    }
}
