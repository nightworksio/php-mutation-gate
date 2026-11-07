<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use function count;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
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
    /** The fewest tests a suite has before a share of them says anything. */
    public const int SMALLEST_SUITE = 20;
    /** The share of the suite's tests that makes a file hot, unless `holds.hotPath` says otherwise. */
    public const float STANDARD = 0.8;

    /** What the warning says. */
    private const string SAID = <<<'SAID'
        `%s` is run by %d of %d tests and nothing holds it; each of its mutants runs most of the suite.
        SAID;

    /** How a hot path stops being one. */
    private const string HOLD = 'Hold it with the tests that assert what it does: #[Holds(\'%s\')] on them.';

    private function __construct(private float $share, private int $fewest)
    {
    }

    public static function standard(): self
    {
        return new self(self::STANDARD, self::SMALLEST_SUITE);
    }

    /** Hot at this share of the suite's tests, a fraction from 0 to 1. */
    public static function atShare(float $share): self
    {
        return new self($share, self::SMALLEST_SUITE);
    }

    /** Hot at this share of a suite of at least so many tests, as `holds.hotPath` and `holds.fewestTests` say. */
    public static function atShareOfAtLeast(float $share, int $fewest): self
    {
        return new self($share, $fewest);
    }

    /** The share of the suite's tests that makes a file hot, as `holds.hotPath` writes it. */
    public function share(): float
    {
        return $this->share;
    }

    /** A warning for each file the suite's map says this share of its tests run, where no held unit holds it. */
    public function in(CoverageMap $suite, Units $held): Warnings
    {
        $warnings = [];

        foreach ($this->hot($suite, $held) as $file) {
            $warnings[] = Warning::noting(self::said($suite, $file));
        }

        return Warnings::of(...$warnings);
    }

    /** What a hot file costs: how many of the suite's tests run it. */
    public static function said(CoverageMap $suite, Path $file): string
    {
        return sprintf(self::SAID, $file->value(), count($suite->testsCoveringFile($file)), count($suite->tests()));
    }

    /** The `#[Holds]` that stops a file being hot. */
    public static function holding(Path $file): string
    {
        return sprintf(self::HOLD, $file->value());
    }

    /** The files the suite's map says this share of its tests run, where no held unit holds them. */
    public function hot(CoverageMap $suite, Units $held): Paths
    {
        $tests = count($suite->tests());
        $hot = Paths::none();

        foreach ($tests < $this->fewest ? [] : $suite->files() as $file) {
            $running = count($suite->testsCoveringFile($file));
            $hot = $running / $tests >= $this->share && ! $this->isHeld($file, $held) ? $hot->with($file) : $hot;
        }

        return $hot;
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
