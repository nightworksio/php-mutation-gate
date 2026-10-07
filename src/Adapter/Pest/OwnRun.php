<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_diff;
use function array_map;

use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * What the plugin recorded of a mutant's own process, by the mutated copy it
 * ran on: the tests that failed or errored in it, in the order they did,
 * which of them errored, the test files it was narrowed to load, the
 * memory limit it ran out of, where it did, whether it had loaded the
 * original file before the mutant was in its place, and how many tests it
 * ran, where it said; and the evidence of its kill: where its killers stood
 * in its order, and how it ended (ADR-0014, decision 16). Any two mutants
 * that leave the same source share their mutated copy, and so this, even
 * under different mutators.
 */
final readonly class OwnRun
{
    /**
     * @param list<string> $killers
     * @param list<string> $errored
     * @param list<string> $loaded
     */
    private function __construct(
        private array $killers,
        private array $errored,
        private array $loaded,
        private MemoryCap|NotGiven $exhaustion,
        private bool $preloaded,
        private int|NotGiven $tests,
        private Places $places,
        private Ended|NotGiven $ended,
    ) {
    }

    /**
     * @param list<string>       $killers    the tests that failed or errored, in order, by their ids
     * @param list<string>       $errored    those of them that errored rather than fail an assertion
     * @param list<string>       $loaded     the test files it was narrowed to load, or none where it loaded every one
     * @param MemoryCap|NotGiven $exhaustion the memory limit it ran out of, or none where it did not
     * @param bool               $preloaded  whether it had loaded the original file before the mutant was in place
     * @param int|NotGiven       $tests      how many tests it ran, or none where it never said
     */
    public static function of(
        array $killers,
        array $errored,
        array $loaded,
        MemoryCap|NotGiven $exhaustion,
        bool $preloaded,
        int|NotGiven $tests,
    ): self {
        $places = Places::none();

        return new self($killers, $errored, $loaded, $exhaustion, $preloaded, $tests, $places, NotGiven::value());
    }

    /** This, with where its killers stood in its order, and how it ended where it failed and that is known. */
    public function withEvidence(Places $places, Ended|NotGiven $ended): self
    {
        return new self(
            $this->killers,
            $this->errored,
            $this->loaded,
            $this->exhaustion,
            $this->preloaded,
            $this->tests,
            $places,
            $ended,
        );
    }

    /**
     * How far it went, the test files it loaded these, where its killers'
     * lines tell (see Places).
     */
    public function prefix(Paths $files): Prefix|NotGiven
    {
        return $this->places->prefix($files);
    }

    /** How it ended, where it failed and one ending is recorded for its copy. */
    public function ended(): Ended|NotGiven
    {
        return $this->ended;
    }

    /** The tests that failed in it, in the order they failed: the first killed the mutant. */
    public function killers(): TestIds
    {
        return TestIds::of(...array_map(TestId::of(...), $this->killers));
    }

    /**
     * Whether every test named as a killer errored rather than fail an
     * assertion, as a test does whose own code a run could not load.
     */
    public function killedByErrorsOnly(): bool
    {
        return $this->killers !== [] && array_diff($this->killers, $this->errored) === [];
    }

    /**
     * The test files it was narrowed to load, by their paths on disk, or none
     * where it loaded every test file.
     *
     * @return list<string>
     */
    public function narrowedTo(): array
    {
        return $this->loaded;
    }

    /** The memory limit it ran out of, where it did. */
    public function exhaustion(): MemoryCap|NotGiven
    {
        return $this->exhaustion;
    }

    /**
     * Whether it had loaded the original file before the mutant was in its
     * place, so that its tests ran the original code.
     */
    public function ranTheOriginal(): bool
    {
        return $this->preloaded;
    }

    /**
     * Whether it said it ran no test, so nothing judged the mutant; one that
     * never said how many it ran is not known to have run none.
     */
    public function ranNoTest(): bool
    {
        return $this->tests === 0;
    }
}
