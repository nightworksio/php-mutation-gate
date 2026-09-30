<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Measurement;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\PhpUnitMemory;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * The memory cap each mutant's process runs under (ADR-0004, decision 9):
 * none at all; one the project's PHPUnit config lifts, since PHPUnit sets its
 * own `memory_limit` after PHP reads the cap; and, under `--measure`, one the
 * suite itself comes near.
 */
final readonly class Memory
{
    private const string UNCAPPED = 'runner.memory is -1, so no mutant\'s process has a memory cap.';

    private const string UNCAPPED_WHY
        = 'A mutant that runs away with memory takes the machine down, with every mutant still to run on it.';

    private const string UNCAPPED_FIX
        = 'Set runner.memory above what the suite needs, such as 1G: doctor --measure says what it needs.';

    private const string LIFTED = '%s sets memory_limit to %s, over the %s cap runner.memory sets.';

    private const string LIFTED_WHY
        = 'PHPUnit sets it as it starts, after PHP reads the cap, so each mutant runs under it in place of the cap.';

    private const string LIFTED_FIX
        = 'Take <ini name="memory_limit"> out of %s, or set it no higher than %s; raise runner.memory to give more.';

    private const string NEAR
        = 'The suite\'s processes peaked at %s resident, over half the %s cap runner.memory sets.';

    private const string NEAR_WHY = <<<'WHY'
        A run runs the suite under the cap before any mutant, and each mutant under it: a mutant that needs more is
        killed by the cap rather than by a test, and a suite over it cannot be judged.
        WHY;

    private const string NEAR_FIX = 'Set runner.memory to at least %s.';

    public static function in(Observations $observed): Findings
    {
        $settings = $observed->settings();

        if (! $settings instanceof Settings) {
            return Findings::none();
        }

        $cap = $settings->runner()->memory();

        return $cap->caps()
            ? self::lifted($cap, $observed->files()->phpUnitMemory())
                ->and(self::near($cap, $observed->asked()->measurement()))
            : self::advice(Slug::MemoryUncapped, self::UNCAPPED, self::UNCAPPED_WHY, self::UNCAPPED_FIX);
    }

    private static function lifted(MemoryCap $cap, PhpUnitMemory|NotGiven $phpUnit): Findings
    {
        return $phpUnit instanceof PhpUnitMemory && $cap->isExceededBy($phpUnit->limit())
            ? self::advice(
                Slug::MemoryCapLifted,
                sprintf(self::LIFTED, $phpUnit->config()->value(), $phpUnit->limit()->written(), $cap->written()),
                self::LIFTED_WHY,
                sprintf(self::LIFTED_FIX, $phpUnit->config()->value(), $cap->written()),
            )
            : Findings::none();
    }

    private static function near(MemoryCap $cap, Measurement|NotGiven $measured): Findings
    {
        $peak = $measured instanceof Measurement ? $measured->peak() : NotGiven::value();

        return $peak instanceof MemoryCap && ! $cap->leavesRoomFor($peak)
            ? self::advice(
                Slug::MemoryCapNear,
                sprintf(self::NEAR, $peak->written(), $cap->written()),
                self::NEAR_WHY,
                sprintf(self::NEAR_FIX, $peak->withRoom()->written()),
            )
            : Findings::none();
    }

    private static function advice(Slug $slug, string $found, string $why, string $fix): Findings
    {
        return Findings::of(Finding::of($slug, Severity::Advice, $found, $why, $fix));
    }
}
