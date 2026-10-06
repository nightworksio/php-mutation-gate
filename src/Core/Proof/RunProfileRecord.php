<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Matrix\MatrixRecord;
use NightWorksIO\MutationGate\Core\Test\SuiteName;

/**
 * A kind of run as the plan and ledger files hold it: `matrix`, `full`
 * where the run records every killer of each mutant, `security`, `true`
 * where it makes mutants with the security mutators alone, and `suite`, the
 * name of the one suite whose tests alone judge them. A record without
 * `matrix` records first killers, one without `security` makes mutants with
 * every mutator the run turns on, and one without `suite` has every test
 * judge them.
 *
 * @internal the shape of the plan and ledger files
 *
 * @phpstan-type Written array{matrix?: string, security?: true, suite?: string}
 */
final readonly class RunProfileRecord
{
    private const string SECURITY = 'security';

    private const string SUITE = 'suite';

    /** @return Written */
    public static function of(RunProfile $kind): array
    {
        $suite = $kind->suite();

        return [
            ...MatrixRecord::of($kind->matrix()),
            ...$kind->isSecurityOnly() ? [self::SECURITY => true] : [],
            ...$suite instanceof SuiteName ? [self::SUITE => $suite->value()] : [],
        ];
    }

    /**
     * The kind of run the record a node holds is of.
     *
     * @throws NotInShape
     */
    public static function read(Node $record): RunProfile
    {
        $suite = $record->field(self::SUITE);

        $kind = RunProfile::standard()->recording(MatrixRecord::read($record->field(MatrixRecord::FIELD)));
        $kind = self::isTrue($record->field(self::SECURITY)) ? $kind->securityOnly() : $kind;

        return $suite->isPresent() ? $kind->inSuite(self::suiteIn($suite)) : $kind;
    }

    /**
     * Whether a field the record writes only where it holds says so: `true`
     * where it does, nothing where it does not.
     *
     * @throws NotInShape
     */
    public static function isTrue(Node $field): bool
    {
        if (! $field->isPresent()) {
            return false;
        }

        return $field->boolean() ? true : throw NotInShape::at($field->at(), 'true');
    }

    /**
     * The suite a record narrows the run's tests to, by its name, which is never empty.
     *
     * @throws NotInShape
     */
    private static function suiteIn(Node $suite): SuiteName
    {
        $name = $suite->text();

        return $name === '' ? throw NotInShape::at($suite->at(), 'a suite\'s name') : SuiteName::of($name);
    }
}
