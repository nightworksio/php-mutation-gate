<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_values;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * A verdict, the lines the run printed beside it (where it wrote, and what
 * it could not write) and the committed baseline it was judged against; and
 * whether the run, having judged, reported and recorded, still cannot pass
 * judgement, as a CI run on a tree held to no floor cannot.
 */
final readonly class Judged
{
    /** @param list<string> $said */
    public function __construct(
        public Verdict $verdict,
        public array $said,
        public Baseline $baseline,
        public bool $refused = false,
    ) {
    }

    /** This verdict, having said these lines too. */
    public function saying(string ...$lines): self
    {
        return new self($this->verdict, [...$this->said, ...array_values($lines)], $this->baseline, $this->refused);
    }

    /** This verdict, which the run reported and recorded but cannot pass judgement on. */
    public function refusing(): self
    {
        return new self($this->verdict, $this->said, $this->baseline, refused: true);
    }

    public function exitCode(): ExitCode
    {
        return match (true) {
            $this->refused => ExitCode::CannotJudge,
            $this->verdict->judgement() === Judgement::Failed => ExitCode::Failed,
            default => ExitCode::Passed,
        };
    }
}
