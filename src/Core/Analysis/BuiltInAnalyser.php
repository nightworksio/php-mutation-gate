<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * The static analysers this package brings, each by the name
 * `staticCheck.tool` chooses it with, in the order `auto` asks about them
 * (ADR-0020, decision 8): Mago leads, being the fastest to check one mutant
 * with. An extension may register others by names of its own.
 */
enum BuiltInAnalyser: string
{
    case Mago = 'mago';
    case PhpStan = 'phpstan';
    case Psalm = 'psalm';

    /** The config files at a project's root that say it runs this analyser, each where the analyser looks. */
    public function configs(): Paths
    {
        return match ($this) {
            self::Mago => Paths::of(Path::of('mago.toml'), Path::of('mago.yaml'), Path::of('mago.json')),
            self::PhpStan => Paths::of(
                Path::of('phpstan.neon'),
                Path::of('phpstan.neon.dist'),
                Path::of('phpstan.dist.neon'),
            ),
            self::Psalm => Paths::of(Path::of('psalm.xml'), Path::of('psalm.xml.dist')),
        };
    }
}
