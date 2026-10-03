<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_key_exists;

use ArrayObject;

use function implode;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;

/**
 * What a ledger's killed records point into and share, as one read of the
 * ledger meets them: its mutator names and its tests, each test built once,
 * each set of killers once, and each line of a unit once. A ledger holds
 * many thousands of kills over far fewer tests and lines, so its proofs
 * share these rather than hold one of each per kill. What it has built is
 * kept as it reads, and never changes what it answers.
 *
 * @internal the shape of the ledger file
 */
final readonly class KilledRecords
{
    private const string SEPARATOR = ',';

    private const string LOCATED = '%s#%d';

    /**
     * @param list<string>                  $mutators  the ledger's mutator names, each at its index
     * @param list<TestId>                  $tests     the ledger's tests, each at its index
     * @param ArrayObject<string, TestIds>  $killers   each set of killers read so far, by its indices
     * @param ArrayObject<string, Location> $locations each line of a unit read so far, by the unit and the line
     */
    private function __construct(
        private array $mutators,
        private array $tests,
        private ArrayObject $killers,
        private ArrayObject $locations,
    ) {
    }

    /**
     * @param list<string> $mutators the ledger's mutator names, each at its index
     * @param list<string> $tests    the ledger's test ids, each at its index
     */
    public static function of(array $mutators, array $tests): self
    {
        $ids = [];

        foreach ($tests as $test) {
            $ids[] = TestId::of($test);
        }

        return new self($mutators, $ids, new ArrayObject(), new ArrayObject());
    }

    /**
     * The mutator a killed record's index names.
     *
     * @throws NotInShape
     */
    public function mutator(Node $index): string
    {
        $at = $index->integer();

        return array_key_exists($at, $this->mutators)
            ? $this->mutators[$at]
            : throw NotInShape::at($index->at(), 'a mutator');
    }

    /**
     * The tests a list of indices into the ledger's tests names, as a killed
     * record's killers or a held unit's proof's judging tests list them, one
     * set for every list that names the same.
     *
     * @throws NotInShape
     */
    public function killers(Node $indices): TestIds
    {
        $listed = $indices->integers();

        return $this->killers[implode(self::SEPARATOR, $listed)] ??= $this->named($indices, $listed);
    }

    /** Where in a unit a kill is, one for every kill on the same line. */
    public function location(Path $unit, Line $line): Location
    {
        $at = sprintf(self::LOCATED, $unit->value(), $line->number());

        return $this->locations[$at] ??= Location::of($unit, $line, Unreported::line());
    }

    /**
     * @param list<int> $listed
     *
     * @throws NotInShape
     */
    private function named(Node $indices, array $listed): TestIds
    {
        $named = [];

        foreach ($listed as $index) {
            $named[] = array_key_exists($index, $this->tests)
                ? $this->tests[$index]
                : throw NotInShape::at($indices->at(), sprintf('the index of a listed test, not %d', $index));
        }

        return TestIds::of(...$named);
    }
}
