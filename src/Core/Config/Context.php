<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;
use function array_pop;
use function implode;
use function sprintf;

/**
 * A context a run is in, by the key of its block under `contexts` (ADR-0070): a developer's machine, any CI
 * run, a proposed change and the default branch.
 */
enum Context: string
{
    case Local = 'local';

    case Ci = 'ci';

    case PullRequest = 'pr';

    case Main = 'main';

    /** The contexts whose blocks a run in this one lays, in order: `pr` and `main` over `ci`. */
    public function chain(): ContextChain
    {
        return match ($this) {
            self::Local => ContextChain::of(self::Local),
            self::Ci => ContextChain::of(self::Ci),
            self::PullRequest => ContextChain::of(self::Ci, self::PullRequest),
            self::Main => ContextChain::of(self::Ci, self::Main),
        };
    }

    /** The method of the PHP builder's `Context` that writes this context's block. */
    public function builder(): string
    {
        return match ($this) {
            self::Local => 'local',
            self::Ci => 'ci',
            self::PullRequest => 'pullRequest',
            self::Main => 'main',
        };
    }

    /** Every context's key, as `--context` and `contexts` take them: `local, ci, pr or main`. */
    public static function words(): string
    {
        $keys = array_map(static fn(self $context): string => $context->value, self::cases());
        $last = array_pop($keys);

        return sprintf('%s or %s', implode(', ', $keys), $last);
    }
}
