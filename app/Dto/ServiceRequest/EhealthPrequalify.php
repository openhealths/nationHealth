<?php

declare(strict_types=1);

namespace App\Dto\ServiceRequest;

use App\Services\MedicalEvents\Fhir;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Prequalification uses the signing fields with the medical program in a separate list. */
#[Map(target: Collection::class)]
class EhealthPrequalify
{
    protected array $content;

    public function __construct(array $resource)
    {
        $this->content = Fhir::serviceRequest()->createContent($resource);
    }

    #[Map(target: '[service_request]')]
    public array $serviceRequest {
        get => Arr::except($this->content, ['id', 'program']);
    }

    #[Map(target: '[programs]')]
    public array $programs {
        get => isset($this->content['program']) ? [$this->content['program']] : [];
    }
}
