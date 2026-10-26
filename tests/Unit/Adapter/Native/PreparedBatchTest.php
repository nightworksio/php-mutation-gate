<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Invocation;
use NightWorksIO\MutationGate\Adapter\Native\MutantRun;
use NightWorksIO\MutationGate\Adapter\Native\PreparedBatch;
use NightWorksIO\MutationGate\Adapter\Native\PreparedRun;
use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutants;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\NativePhpUnit;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitScan;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitShellFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A batch of one run at a time over a project of one mutant, its end at
 * this reading of `hrtime`, with the run of that mutant and the shell its
 * runs go to.
 *
 * @return array{PreparedBatch, PreparedRun, PhpUnitShellFake}
 */
function batchEnding(float|int $end): array
{
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', "<?php\n\nfunction add(\$a, \$b)\n{\n    return \$a + \$b;\n}\n");
    $project = Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::PhpUnit);
    $shell = PhpUnitShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $run = new MutantRun($project, $shell, new Invocation($project, '/gate/override.php', NativePhpUnit::in($project)), NativePhpUnit::in($project), PhpUnitScan::uncapped($project), NotGiven::value());
    $made = Engine::with(new PlusToMinus())->mutantsOf(Path::of('src/Money.php'), Contents::of((string) file_get_contents(sprintf('%s/src/Money.php', $root))));
    $prepared = $made instanceof MadeMutants ? $run->prepared(
        [...$made][0],
        TestIds::of(TestId::of('Tests\MoneyTest::adds')),
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()),
        Seconds::of(3.0),
    ) : null;

    return [
        PreparedBatch::of($run, WorkerSlots::of(ProcessCount::single(), 'batch'), 1, $end),
        $prepared instanceof PreparedRun ? $prepared : throw new LogicException('Money has no run.'),
        $shell,
    ];
}

it('runs out once its end has passed, and never where it has none', function (): void {
    $now = hrtime(as_number: true);

    expect([
        batchEnding($now - 1)[0]->hasRunOut(),
        batchEnding($now + Seconds::of(60.0)->nanoseconds())[0]->hasRunOut(),
        batchEnding(PHP_INT_MAX)[0]->hasRunOut(),
    ])->toBe([true, false, false]);
});

it('gives each batch the time left to start its runs in: all the time there is with no end, none once the end has passed', function (): void {
    $within = static function (float|int $end): Seconds|Unlimited {
        [$batch, $prepared, $shell] = batchEnding($end);
        $batch->add($prepared);

        return $shell->within()[0];
    };
    $left = $within(hrtime(as_number: true) + Seconds::of(5.0)->nanoseconds());

    expect($within(PHP_INT_MAX))->toEqual(Unlimited::time())
        ->and($within(hrtime(as_number: true) - 1))->toEqual(Seconds::of(0.0))
        ->and($left instanceof Seconds ? [$left->seconds() > 4.0, $left->seconds() <= 5.0] : $left)->toBe([true, true]);
});
