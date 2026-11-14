<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * `pest.preloaded`: what becomes of a mutant whose own Pest run had loaded its file before the mutant was in
 * place, so its tests ran the original code (ADR-0148).
 */
enum Preloaded: string
{
    /** It is judged again in a fresh Pest process, the mutant served before Composer's autoloader loads anything. */
    case Fresh = 'fresh';

    /** It stays unjudged. */
    case Unjudged = 'unjudged';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Fresh => 'Pest::preloadedFresh()',
            self::Unjudged => 'Pest::preloadedUnjudged()',
        };
    }
}
