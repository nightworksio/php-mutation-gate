<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;
use function array_pop;
use function implode;
use function in_array;

use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * A context a run is in, by the key of its block under `contexts` (ADR-0071, ADR-0104): a developer's machine,
 * any CI run, the CI it runs in, a proposed change and the default branch.
 */
enum Context: string
{
    case Local = 'local';

    case Ci = 'ci';

    case GitHub = 'github';

    case GitLab = 'gitlab';

    case Bitbucket = 'bitbucket';

    case Azure = 'azure';

    case Buildkite = 'buildkite';

    case Jenkins = 'jenkins';

    case CircleCi = 'circleci';

    case PullRequest = 'pr';

    case Main = 'main';

    /**
     * The contexts whose blocks a run in this one lays, in order: a CI's, `pr` and `main` over `ci`. A run in a CI
     * lays its CI's block between `ci` and the kind of run's (see `RunContext`).
     */
    public function chain(): ContextChain
    {
        return match ($this) {
            self::Local => ContextChain::of(self::Local),
            self::Ci => ContextChain::of(self::Ci),
            self::PullRequest, self::Main, self::GitHub, self::GitLab, self::Bitbucket, self::Azure, self::Buildkite,
            self::Jenkins, self::CircleCi => ContextChain::of(self::Ci, $this),
        };
    }

    /** The CI a run in a job of this CI plan's is in; none for a plan of no CI's own. */
    public static function ofPlan(string $plan): self|NotGiven
    {
        $context = self::tryFrom($plan);

        return $context instanceof self && $context->isCi() ? $context : NotGiven::value();
    }

    /** Whether this is a CI's own context. */
    public function isCi(): bool
    {
        return ! in_array($this, [self::Local, self::Ci, self::PullRequest, self::Main], strict: true);
    }

    /** The method of the PHP builder's `Context` that writes this context's block. */
    public function builder(): string
    {
        return match ($this) {
            self::PullRequest => 'pullRequest',
            self::GitHub => 'gitHub',
            self::GitLab => 'gitLab',
            self::CircleCi => 'circleCi',
            self::Local, self::Ci, self::Main, self::Bitbucket, self::Azure, self::Buildkite, self::Jenkins
                => $this->value,
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
