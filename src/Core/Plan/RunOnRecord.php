<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Proof\Scope;

/**
 * Where a plan's run stands, as the plan file holds it at its top level:
 * `ref`, the run's scope, `defaultBranch`, and `target`, the branch a pull
 * request merges into, each as a full ref where it is named. A plan with no
 * `ref` was made on a detached `HEAD`.
 *
 * @internal the shape of the run's fields of the plan file
 *
 * @phpstan-type Written array{ref?: string, defaultBranch?: string, target?: string}
 */
final readonly class RunOnRecord
{
    private const string REF = 'ref';

    private const string DEFAULT_BRANCH = 'defaultBranch';

    private const string TARGET = 'target';

    private const string UNNAMED = 'The plan names no default branch.';

    /** @return Written each field the run names */
    public static function of(RunOn $runOn): array
    {
        $scope = $runOn->scope();
        $defaultBranch = $runOn->defaultBranch();
        $target = $runOn->target();

        return [
            ...$scope instanceof Scope ? [self::REF => $scope->ref()] : [],
            ...$defaultBranch instanceof Scope ? [self::DEFAULT_BRANCH => $defaultBranch->ref()] : [],
            ...$target instanceof Scope ? [self::TARGET => $target->ref()] : [],
        ];
    }

    /**
     * The run a plan file names.
     *
     * @throws NotInShape
     */
    public static function read(Node $file): RunOn
    {
        $named = $file->field(self::DEFAULT_BRANCH);
        $defaultBranch = $named->isPresent() ? self::scopeIn($named) : CannotTell::because(self::UNNAMED);
        $ref = $file->field(self::REF);
        $target = $file->field(self::TARGET);
        $run = $ref->isPresent() ? RunOn::at(self::scopeIn($ref), $defaultBranch) : RunOn::detached($defaultBranch);

        return $target->isPresent() ? $run->withTarget(self::scopeIn($target)) : $run;
    }

    /** @throws NotInShape */
    private static function scopeIn(Node $ref): Scope
    {
        $scope = Scope::parse($ref->text());

        return $scope instanceof CannotJudge ? throw NotInShape::at($ref->at(), 'a scope') : $scope;
    }
}
