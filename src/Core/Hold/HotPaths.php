<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use function count;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * The source files most of a suite runs through that nothing holds. Each of
 * their mutants runs most of the suite, which costs time and never a verdict,
 * so they are named in a warning.
 */
final readonly class HotPaths
{
    /** The share of the suite's tests that makes a file hot, unless `holds.hotPath` says otherwise. */
    private const float STANDARD = 0.8;

    /** The fewest tests a suite has before a share of them says anything. */
    private const int SMALLEST_SUITE = 20;

    /** What the warning says. */
    private const string SAID = <<<'SAID'
        `%s` is run by %d of %d tests and nothing holds it; each of its mutants runs most of the suite.
        SAID;

    private function __construct(private float $share)
    {
    }

    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    /** Hot at this share of the suite's tests, a fraction from 0 to 1. */
    public static function atShare(float $share): self
    {
        return new self($share);
    }

    /** A warning for each file the suite's map says this share of its tests run, where no held unit holds it. */
    public function in(CoverageMap $suite, Units $held): Warnings
    {
        $tests = count($suite->tests());
        $warnings = Warnings::none();

        foreach ($tests < self::SMALLEST_SUITE ? [] : $suite->files() as $file) {
            $running = count($suite->testsCoveringFile($file));
            $warnings = $running / $tests >= $this->share && ! $this->isHeld($file, $held)
                ? $warnings->with(Warning::that(sprintf(self::SAID, $file->value(), $running, $tests)))
                : $warnings;
        }

        return $warnings;
    }

    private function isHeld(Path $file, Units $held): bool
    {
        foreach ($held as $unit) {
            if ($unit->isHeld() && $file->within($unit->path())) {
                return true;
            }
        }

        return false;
    }
}
