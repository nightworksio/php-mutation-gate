<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Matrix\MatrixRecord;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\WrittenBytes;
use NightWorksIO\MutationGate\Core\Test\SuiteName;

/**
 * A briefing as the plan file holds it: `peak`, the bytes the unmutated
 * suite's largest process held, where the plan measured them, `matrix`,
 * `full` where the run records every killer of each mutant, and `security`,
 * `true` where the run makes mutants with the security mutators alone,
 * `suite`, the name of the one suite whose tests alone judge the mutants, and
 * `ownScopeCoverage`, `true` where the coverage map was measured against the
 * map the run's own scope keeps. A file without `peak` measured none, one
 * without `matrix` records first killers, one without `security` makes
 * mutants with every mutator the run turns on, one without `suite` has every
 * test judge them, and one without `ownScopeCoverage` measured against no
 * map of its own scope.
 *
 * @internal the shape of the plan file
 *
 * @phpstan-type Written array{peak?: int, matrix?: string, security?: true, suite?: string, ownScopeCoverage?: true}
 */
final readonly class BriefingRecord
{
    private const string PEAK = 'peak';

    private const string SECURITY = 'security';

    private const string SUITE = 'suite';

    /** @return Written */
    public static function of(Briefing $briefing): array
    {
        $peak = $briefing->peak();
        $suite = $briefing->suite();

        return [
            ...$peak instanceof MemoryCap ? [self::PEAK => $peak->bytes()] : [],
            ...MatrixRecord::of($briefing->matrix()),
            ...$briefing->isSecurityOnly() ? [self::SECURITY => true] : [],
            ...$suite instanceof SuiteName ? [self::SUITE => $suite->value()] : [],
            ...$briefing->isOnOwnScopeCoverage() ? [LedgerFile::OWN_SCOPE_COVERAGE => true] : [],
        ];
    }

    /**
     * The briefing a plan file holds.
     *
     * @throws NotInShape
     */
    public static function read(Node $file): Briefing
    {
        $peak = $file->field(self::PEAK);
        $briefing = Briefing::standard()
            ->weighing($peak->isPresent() ? WrittenBytes::read($peak) : NotGiven::value())
            ->recording(MatrixRecord::read($file->field(MatrixRecord::FIELD)));

        $briefing = self::isTrue($file->field(self::SECURITY)) ? $briefing->securityOnly() : $briefing;
        $own = self::isTrue($file->field(LedgerFile::OWN_SCOPE_COVERAGE));
        $briefing = $own ? $briefing->onOwnScopeCoverage() : $briefing;
        $suite = $file->field(self::SUITE);

        return $suite->isPresent() ? $briefing->inSuite(self::suiteIn($suite)) : $briefing;
    }

    /**
     * The suite a file narrows the run's tests to, by its name, which is never empty.
     *
     * @throws NotInShape
     */
    private static function suiteIn(Node $suite): SuiteName
    {
        $name = $suite->text();

        return $name === '' ? throw NotInShape::at($suite->at(), 'a suite\'s name') : SuiteName::of($name);
    }

    /**
     * Whether a field the file writes only where it holds says so: `true`
     * where it does, nothing where it does not.
     *
     * @throws NotInShape
     */
    private static function isTrue(Node $field): bool
    {
        if (! $field->isPresent()) {
            return false;
        }

        return $field->boolean() ? true : throw NotInShape::at($field->at(), 'true');
    }
}
