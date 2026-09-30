<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Config\Definition\Fields;
use NightWorksIO\MutationGate\Core\Config\Definition\Json;

/**
 * The one validator every config format goes through (ADR-0002): the
 * untyped tree read into settings, or every problem in it at once, each at
 * its path with what was expected there.
 */
final readonly class Validator
{
    /** @param DateTimeImmutable $now the instant `ignores.maxDays` counts from */
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function validate(Document $document): Settings|Invalid
    {
        $reading = Definition::config($this->now)->read(Json::decode($document->json()), '');
        $read = $reading->value();

        return $read instanceof Fields
            ? Settings::from($read, Json::pretty($reading->shown()), Json::canonical($reading->results()))
            : Invalid::because(...$reading->problems());
    }
}
