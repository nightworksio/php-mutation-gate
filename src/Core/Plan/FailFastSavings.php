<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function max;
use function min;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * What stopping would have saved a pull request's run whose shards judged on once a survivor left it no way to
 * pass, as `run.failFast: mark` has them (ADR-0103): each doomed shard's mutating after it found its survivor, for
 * `stop`; and every shard's after the first was found, for `cancel`, its shards taken to start together. Nothing
 * where no shard judged on past one.
 *
 * @internal
 */
final readonly class FailFastSavings
{
    private const string SAID = <<<'SAID'
        This run could not pass %s into its shards' mutating, and they judged on, as run.failFast: mark has them.
        run.failFast: stop would have saved about %s of runner time, and cancel about %s.
        SAID;

    /** @param list<ShardResult> $results */
    private function __construct(private array $results)
    {
    }

    /** @param list<ShardResult> $results each shard's */
    public static function of(array $results): self
    {
        return new self($results);
    }

    /** The suggestion of `stop` or `cancel`, with what each would have saved; none where no shard judged on. */
    public function warnings(): Warnings
    {
        $stop = 0.0;
        $first = [];

        foreach ($this->results as $result) {
            $doomed = $result->doomed();
            $found = $doomed instanceof Doomed && ! $doomed->stopped() ? [$doomed->after()->seconds()] : [];
            $stop += $found === [] ? 0.0 : max(0.0, $result->measured()->spent()->seconds() - $found[0]);
            $first = [...$first, ...$found];
        }

        return $first === [] ? Warnings::none() : Warnings::of(Warning::that(sprintf(
            self::SAID,
            Seconds::of(min($first))->text(),
            Seconds::of($stop)->text(),
            Seconds::of($this->after(min($first)))->text(),
        )));
    }

    /** Every shard's mutating after this many seconds into it. */
    private function after(float $first): float
    {
        $saved = 0.0;

        foreach ($this->results as $result) {
            $saved += max(0.0, $result->measured()->spent()->seconds() - $first);
        }

        return $saved;
    }
}
