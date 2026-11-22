<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_filter;
use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShare;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Verdict\Warning;

use function sprintf;

/**
 * A result whose runner died after it had made its mutants and before it had
 * judged them all (ADR-0330). None of its records is trusted, so every mutant
 * it was to judge stays unjudged, saying so, and the verdict warns which job
 * crashed and what its runner said. It voids no other result.
 *
 * @internal
 */
final readonly class Crashed
{
    private const string SHARD = "Shard %d (%s) crashed, so each of its units is unjudged. Its runner said:\n%s";

    private const string SHARE = <<<'SAID'
        Share %d of the mutants shard %d (%s) left crashed, so its %d mutants are unjudged. Its runner said:
        %s
        SAID;

    private const string REASON = 'Share %d of the mutants shard %d left crashed before it judged this mutant.';

    private function __construct(private ShardResult $result, private string $why)
    {
    }

    /** This result of a runner that crashed, saying this. */
    public static function of(ShardResult $result, string $why): self
    {
        return new self($result, $why);
    }

    /**
     * The result of a shard of the first wave that crashed: no mutant, each
     * of its units unjudged, and a warning of the crash.
     *
     * @return array{ShardResult, MutationResult}
     */
    public function ofShard(Shard $shard): array
    {
        $none = MutationResult::skippingNone(Mutants::none());
        $result = ShardResult::of(
            $this->result->plan(),
            $this->result->shard(),
            $this->result->units(),
            $none,
            $this->result->measured(),
        );
        $warned = Warning::that(sprintf(self::SHARD, $shard->id()->number(), $shard->label(), $this->why));

        return [$result->withUnjudged($shard->units())->withWarnings($result->warnings()->with($warned)), $none];
    }

    /**
     * The mutants a share that crashed lists, each as its shard left it but
     * unjudged for the crash; and a warning of the crash.
     *
     * @return array{Mutants, Warning}
     */
    public function ofShare(Shard $shard, LeftoverShare $share, MutationResult $left): array
    {
        $number = $shard->id()->number();
        $reason = Reason::that(sprintf(self::REASON, $share->number(), $number));
        $listed = array_values(array_filter(
            [...$left->mutants()],
            static fn(Mutant $mutant): bool => $share->mutants()->has($mutant->id()),
        ));

        return [
            Mutants::of(...array_map(static fn(Mutant $mutant): Mutant => $mutant->unjudged($reason), $listed)),
            Warning::that(sprintf(
                self::SHARE,
                $share->number(),
                $number,
                $shard->label(),
                $share->mutants()->count(),
                $this->why,
            )),
        ];
    }
}
