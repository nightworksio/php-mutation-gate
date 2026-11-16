<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * How a round of `watch` ended: whether it planned, and so left a coverage
 * map for the next round, and what it judged or why it judged nothing.
 *
 * @internal
 */
final readonly class RoundEnd
{
    public function __construct(private bool $planned, private Judged|Invalid|CannotJudge $judged)
    {
    }

    /** Whether the round planned, and so left a coverage map for the next. */
    public function planned(): bool
    {
        return $this->planned;
    }

    /** What `watch` exits with where this round is its last: passed where it judged, failed where it could not. */
    public function exitCode(OutputInterface $output): int
    {
        return $this->judged instanceof Judged ? ExitCode::Passed->value : Failed::because($output, $this->judged);
    }
}
