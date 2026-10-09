<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function in_array;

use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\PreCheckables;
use NightWorksIO\MutationGate\Core\Analysis\PreChecker;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\Analysis\Rejections;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;

use function sprintf;

/** A pre-checker that rejects every mutant of these mutators, and keeps what it was offered. */
final class PreCheckerFake implements PreChecker
{
    /** @var list<string> each mutant offered, as its mutator and its text, in the order offered */
    private array $offered = [];

    /** @var list<int> how many checks it was allowed to run side by side, each time it was asked */
    private array $sides = [];

    /** @param list<string> $rejecting the mutators whose mutants it rejects */
    public function __construct(private readonly array $rejecting)
    {
    }

    /** The rejection it gives a mutant of this file. */
    public static function rejection(Path $file): Rejection
    {
        return Rejection::by('fake', Finding::error($file, 'rejected', 'The fake rejects it.'));
    }

    public function rejected(PreCheckables $mutants, ProcessCount $side): Rejections
    {
        $this->sides[] = $side->count();
        $rejections = Rejections::none();

        foreach ($mutants as $offered) {
            $mutant = $offered->mutant();
            $this->offered[] = sprintf('%s %s', $mutant->mutator(), $offered->checkable()->mutant()->text());
            $rejections = in_array($mutant->mutator(), $this->rejecting, strict: true)
                ? $rejections->with($mutant->id(), self::rejection($mutant->location()->file()))
                : $rejections;
        }

        return $rejections;
    }

    /** @return list<string> */
    public function offered(): array
    {
        return $this->offered;
    }

    /** @return list<int> */
    public function sides(): array
    {
        return $this->sides;
    }
}
