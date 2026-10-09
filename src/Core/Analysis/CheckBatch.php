<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_values;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;

/**
 * Checks an analyser runs as processes of their own, side by side (ADR-0020,
 * decision 12): for each check, the command that analyses it, or the answer
 * it has without one, out of scope or why it cannot run. Once the commands
 * have run, each answer stands in its check's place.
 */
final readonly class CheckBatch
{
    /** Why a check whose command never started cannot judge. */
    private const string UNSTARTED = 'The analyser did not start the check.';

    /** @param list<ProcessCommand|Findings|OutOfScope|CannotJudge> $prepared each check's command or answer */
    private function __construct(private array $prepared)
    {
    }

    public static function of(ProcessCommand|Findings|OutOfScope|CannotJudge ...$prepared): self
    {
        return new self(array_values($prepared));
    }

    /**
     * The commands to run, in the order of their checks.
     *
     * @return list<ProcessCommand>
     */
    public function commands(): array
    {
        $commands = [];

        foreach ($this->prepared as $prepared) {
            if ($prepared instanceof ProcessCommand) {
                $commands[] = $prepared;
            }
        }

        return $commands;
    }

    /**
     * Each check's answer: what its command's end answers, read by its place
     * among the checks, or the answer it had. A command that never started
     * cannot judge.
     *
     * @param Closure(Ran, int): (Findings|CannotJudge) $answer what an end answers, given its check's place
     */
    public function answered(ProcessEnds $ends, Closure $answer): CheckAnswers
    {
        $ran = [...$ends];
        $places = array_keys(array_filter(
            $this->prepared,
            static fn(object $prepared): bool => $prepared instanceof ProcessCommand,
        ));
        $answers = $this->prepared;

        foreach ($places as $position => $at) {
            $answers[$at] = array_key_exists($position, $ran)
                ? $answer($ran[$position], $at)
                : CannotJudge::because(self::UNSTARTED);
        }

        return CheckAnswers::of(...array_filter(
            $answers,
            static fn(object $answered): bool => ! $answered instanceof ProcessCommand,
        ));
    }
}
