<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * A run that vouches for a kill on the unmutated code: the tests a mutant's
 * run ran, in the test files it loaded, every one where it names none, with
 * the file the mutant changes served unmutated through Pest's override (see
 * ServedOriginal).
 */
final readonly class Control
{
    private function __construct(private Path $source, private Paths $tests, private WholeSuite|Group|Filter $judgedBy)
    {
    }

    public static function of(Path $source, Paths $tests, WholeSuite|Group|Filter $judgedBy): self
    {
        return new self($source, $tests, $judgedBy);
    }

    /** The file the mutant changes, served unmutated. */
    public function source(): Path
    {
        return $this->source;
    }

    /** The test files the run loads; none where it loads every one. */
    public function tests(): Paths
    {
        return $this->tests;
    }

    public function judgedBy(): WholeSuite|Group|Filter
    {
        return $this->judgedBy;
    }
}
