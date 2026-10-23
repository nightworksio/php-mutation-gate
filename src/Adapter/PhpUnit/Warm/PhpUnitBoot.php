<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use NightWorksIO\MutationGate\Core\Native\WarmBoot;
use NightWorksIO\MutationGate\Core\NotGiven;
use PHPUnit\Event\Facade;

/** A warm worker's boot as PHPUnit boots before it runs: its config's PHP settings, then its bootstrap. */
final readonly class PhpUnitBoot implements WarmBoot
{
    /** PHPUnit's event facade, which PHPUnit before 13.4 starts as its config loads. */
    public function events(): string
    {
        return Facade::class;
    }

    public function configured(string|NotGiven $config): string|NotGiven
    {
        $loaded = $config instanceof NotGiven ? NotGiven::value() : Configuring::installed()->loader()->load($config);

        if ($loaded instanceof NotGiven) {
            return $loaded;
        }

        Configuring::installed()->handler()->handle($loaded->php());

        return $loaded->phpunit()->hasBootstrap() ? $loaded->phpunit()->bootstrap() : NotGiven::value();
    }
}
