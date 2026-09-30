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
 * it could not write) and the committed baseline it was judged against.
 */
final readonly class Judged
{
    /** @param list<string> $said */
    public function __construct(public Verdict $verdict, public array $said, public Baseline $baseline)
    {
    }

    /** This verdict, having said these lines too. */
    public function saying(string ...$lines): self
    {
        return new self($this->verdict, [...$this->said, ...array_values($lines)], $this->baseline);
    }

    public function exitCode(): ExitCode
    {
        return $this->verdict->judgement() === Judgement::Failed ? ExitCode::Failed : ExitCode::Passed;
    }
}
