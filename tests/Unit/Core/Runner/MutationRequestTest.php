<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\Coverage\Fresh;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

it('asks for some files judged by some tests, and by default nothing more', function (): void {
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());

    expect($request->files())->toEqual(Paths::of(Path::of('src/Money.php')))
        ->and($request->judgedBy())->toEqual(WholeSuite::tests())
        ->and($request->leftOut())->toEqual(Paths::none())
        ->and($request->mutators())->toEqual(Mutators::all())
        ->and($request->deadline())->toEqual(Unlimited::time())
        ->and($request->processes())->toEqual(Processes::of(1))
        ->and($request->coverage())->toEqual(Fresh::coverage())
        ->and($request->ordering())->toEqual(Ordering::runner());
});

it('takes each setting on its own, leaving the rest as they were', function (): void {
    $request = MutationRequest::of(Paths::of(Path::of('src')), Group::named('slow'))
        ->leavingOut(Paths::of(Path::of('src/Kernel.php')))
        ->onlyMutators(Mutators::named('LessThan'))
        ->within(Seconds::of(600.0))
        ->across(Processes::of(8))
        ->reusingCoverage(Path::of('.mutation-gate/coverage'))
        ->orderedBy(Ordering::of(TestOrder::KillersFirst, KillHistory::none()));

    expect($request->files())->toEqual(Paths::of(Path::of('src')))
        ->and($request->judgedBy())->toEqual(Group::named('slow'))
        ->and($request->leftOut())->toEqual(Paths::of(Path::of('src/Kernel.php')))
        ->and($request->mutators())->toEqual(Mutators::named('LessThan'))
        ->and($request->deadline())->toEqual(Seconds::of(600.0))
        ->and($request->processes())->toEqual(Processes::of(8))
        ->and($request->coverage())->toEqual(Path::of('.mutation-gate/coverage'))
        ->and($request->ordering())->toEqual(Ordering::of(TestOrder::KillersFirst, KillHistory::none()));
});

it('leaves the request it came from as it was', function (): void {
    $request = MutationRequest::of(Paths::none(), Filter::matching('KernelTest'));
    $request->leavingOut(Paths::of(Path::of('a')));
    $request->onlyMutators(Mutators::named('LessThan'));
    $request->within(Seconds::of(1.0));
    $request->across(Processes::of(2));
    $request->reusingCoverage(Path::of('c'));

    expect($request)->toEqual(MutationRequest::of(Paths::none(), Filter::matching('KernelTest')));
});

it('withholds the CI\'s credentials by default, and more where it is told, never fewer', function (): void {
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
    $more = $request->withholding(Withheld::of('DEPLOY_*'));

    expect($request->withheld())->toEqual(Withheld::standard())
        ->and($more->withheld())->toEqual(Withheld::standard()->and(Withheld::of('DEPLOY_*')))
        ->and($more->withholding(Withheld::nothing())->withheld())->toEqual($more->withheld())
        ->and($more->files())->toBe($request->files());
});

it('never tells a runner how uncovered mutants score, which the gate applies when it judges', function (): void {
    $methods = new ReflectionClass(MutationRequest::class)->getMethods(ReflectionMethod::IS_PUBLIC);
    $typed = array_map(
        static fn(ReflectionMethod $method): string => sprintf('%s %s', $method->getReturnType(), implode(' ', array_map(
            static fn(ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $method->getParameters(),
        ))),
        $methods,
    );

    expect(array_filter($typed, static fn(string $types): bool => str_contains($types, 'Uncovered')))->toBe([]);
});
