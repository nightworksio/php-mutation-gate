<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Score\Floor;

use function sprintf;

/**
 * The survivor that makes a pull request's run certain to fail (ADR-0008,
 * decision 6): its unit, its id, the tree that holds it, the floor of 100 it
 * fails and which floor that is. The shard it is in stops there, and the
 * verdict fails naming it.
 */
final readonly class Doomed
{
    private const string SAID = <<<'SAID'
        Shard %1$d stopped once this run could not pass: mutant %2$s of %3$s survived, and %4$s.
        The units it did not run are unjudged. Once that mutant is killed, a run judges them.
        SAID;

    private const string LEFT = <<<'LEFT'
        The run stopped before this unit, once mutant %s of %s made it certain to fail. Kill that mutant and run again.
        LEFT;

    private const string BY_TREE = 'the floor of tree %s is %s%%';

    private const string BY_NEW_CODE = 'it is on a line the change added or modified, whose new-code floor is %s%%';

    private function __construct(
        private Path $unit,
        private MutantId $mutant,
        private Path $tree,
        private Floor $floor,
        private DoomedBy $by,
    ) {
    }

    public static function of(Path $unit, MutantId $mutant, Path $tree, Floor $floor, DoomedBy $by): self
    {
        return new self($unit, $mutant, $tree, $floor, $by);
    }

    /** The unit the survivor is a mutant of. */
    public function unit(): Path
    {
        return $this->unit;
    }

    /** The survivor, by the gate's id. */
    public function mutant(): MutantId
    {
        return $this->mutant;
    }

    /** The tree that holds the survivor's unit. */
    public function tree(): Path
    {
        return $this->tree;
    }

    /** The floor the survivor fails. */
    public function floor(): Floor
    {
        return $this->floor;
    }

    /** Which floor that is: its tree's, or the new code's. */
    public function by(): DoomedBy
    {
        return $this->by;
    }

    /** Why a mutant of a unit the run stopped before is unjudged, where its newest result does not stand for it. */
    public function left(): Reason
    {
        return Reason::that(sprintf(self::LEFT, $this->mutant->value(), $this->unit->value()));
    }

    /** Why the verdict fails, of the shard that stopped here. */
    public function said(int $shard): string
    {
        $floor = (string) $this->floor->written();
        $why = $this->by === DoomedBy::Tree
            ? sprintf(self::BY_TREE, $this->tree->value(), $floor)
            : sprintf(self::BY_NEW_CODE, $floor);

        return sprintf(self::SAID, $shard, $this->mutant->value(), $this->unit->value(), $why);
    }
}
