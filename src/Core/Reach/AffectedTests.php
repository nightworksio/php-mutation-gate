<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_key_exists;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * The tests a change can make fail (ADR-0020, decisions 1 and 2): each test
 * file with the coverage map's tests in it the change reaches and why, or
 * every test file of the suite where some reason reaches every test; the
 * changed source files no test runs or reads; and the reasons that list no
 * test at all.
 */
final readonly class AffectedTests
{
    /**
     * @param array<string, AffectedTest> $tests     each test file listed, by its path, in the order first listed
     * @param Reasons                     $every     why every test is listed; none where the list is the files
     * @param Paths                       $unreached each changed source file no test runs or reads
     * @param Reasons                     $nothing   the reasons that list no test
     */
    private function __construct(
        private TestPlaces $places,
        private array $tests,
        private Reasons $every,
        private Paths $unreached,
        private Reasons $nothing,
    ) {
    }

    /** No test, of a suite whose test files hold the map's tests so. */
    public static function none(TestPlaces $places): self
    {
        return new self($places, [], Reasons::of(), Paths::none(), Reasons::of());
    }

    /** Every test of the suite, for this reason. */
    public static function every(TestPlaces $places, Reason $why): self
    {
        return self::none($places)->all($why);
    }

    /** These, with some of a test file's tests listed for a reason. */
    public function reaching(Path $file, TestIds $tests, Reason $why): self
    {
        $listed = $this->tests;
        $listed[$file->value()] = array_key_exists($file->value(), $listed)
            ? $listed[$file->value()]->and($tests, $why)
            : AffectedTest::of($file, $tests, Reasons::of($why));

        return new self($this->places, $listed, $this->every, $this->unreached, $this->nothing);
    }

    /** These, with a whole test file listed for a reason: every one of the map's tests it holds. */
    public function wholly(Path $file, Reason $why): self
    {
        return $this->reaching($file, $this->places->in($file), $why);
    }

    /** These, with every test of the suite listed for a reason. */
    public function all(Reason $why): self
    {
        return new self($this->places, $this->tests, $this->every->with($why), $this->unreached, $this->nothing);
    }

    /** These, with a changed source file that no test runs or reads, for a reason. */
    public function leaving(Path $source, Reason $why): self
    {
        return new self(
            $this->places,
            $this->tests,
            $this->every,
            $this->unreached->with($source),
            $this->nothing->with($why),
        );
    }

    /** These, with a reason that lists no test. */
    public function because(Reason $why): self
    {
        return new self($this->places, $this->tests, $this->every, $this->unreached, $this->nothing->with($why));
    }

    /** These, with everything another answer over the same suite lists, leaves and gives as reasons besides. */
    public function and(self $other): self
    {
        $tests = $this->tests;

        foreach ($other->tests as $path => $test) {
            $tests[$path] = array_key_exists($path, $tests) ? $tests[$path]->with($test) : $test;
        }

        $every = $this->every;
        $unreached = $this->unreached;
        $nothing = $this->nothing;

        foreach ($other->every as $why) {
            $every = $every->with($why);
        }

        foreach ($other->unreached as $source) {
            $unreached = $unreached->with($source);
        }

        foreach ($other->nothing as $why) {
            $nothing = $nothing->with($why);
        }

        return new self($this->places, $tests, $every, $unreached, $nothing);
    }

    /** Whether no reason lists any test. */
    public function listsNone(): bool
    {
        return $this->tests === [] && ! $this->isEvery();
    }

    /** Whether some reason lists every test of the suite. */
    public function isEvery(): bool
    {
        return count($this->every) > 0;
    }

    /**
     * Each test file listed, with its tests and its reasons: where every test
     * is listed, every test file of the suite, each with every one of the
     * map's tests it holds and the reasons every test is listed for besides
     * its own.
     *
     * @return list<AffectedTest>
     */
    public function tests(): array
    {
        if (! $this->isEvery()) {
            return $this->reached();
        }

        $every = [];

        foreach ($this->places->files() as $file) {
            $every[] = $this->everyOf($file);
        }

        return $every;
    }

    /**
     * Each test file some reason reached, with its tests and its own reasons,
     * whether or not every test is listed besides.
     *
     * @return list<AffectedTest>
     */
    public function reached(): array
    {
        return array_values($this->tests);
    }

    /** Each changed source file no test runs or reads. */
    public function unreached(): Paths
    {
        return $this->unreached;
    }

    /** Why every test is listed; none where the list is the files the change reaches. */
    public function everyBecause(): Reasons
    {
        return $this->every;
    }

    /** The reasons that list no test. */
    public function nothingBecause(): Reasons
    {
        return $this->nothing;
    }

    /** A test file of the suite as every test lists it: all its tests, for every reason, and for its own first. */
    private function everyOf(Path $file): AffectedTest
    {
        $reasons = array_key_exists($file->value(), $this->tests)
            ? $this->tests[$file->value()]->reasons()
            : Reasons::of();

        foreach ($this->every as $why) {
            $reasons = $reasons->with($why);
        }

        return AffectedTest::of($file, $this->places->in($file), $reasons);
    }
}
