<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\PackageDirectory;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Runner\Leftover;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Tests\Support\PestCases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

afterEach(function (): void {
    Scratch::sweep();
});

it('lists the tests of some suites and their groups as Pest lists them into a file of its own', function (): void {
    $at = PestCases::project();
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::listed($command));
    $pest = new Pest($at, $shell, Patching::off(), new CapDirectory(), new PackageDirectory(), Triage::standard()->limits()->bounds());

    $listing = $pest->listing(Withheld::of('CI_JOB_TOKEN'), Suites::listed('Unit'));

    expect($listing instanceof TestListing ? [...$listing->groups()] : $listing)
        ->toEqual([Group::named('default'), Group::named('mutation-canary')])
        ->and($listing instanceof TestListing ? $listing->inGroup(Group::named('mutation-canary')) : $listing)
        ->toEqual(TestIds::of(TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_is_alive')))
        ->and($shell->commands())->toEqual([
            PestCases::invocation($at)->listing(Withheld::of('CI_JOB_TOKEN'), Suites::listed('Unit'), PestCases::listingFile($at)),
        ]);
});

it('lists the tests of each set of suites once', function (): void {
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::listed($command));
    $pest = new Pest(PestCases::project(), $shell, Patching::off(), new CapDirectory(), new PackageDirectory(), Triage::standard()->limits()->bounds());

    $pest->listing(Withheld::standard(), Suites::listed('Unit'));
    $pest->listing(Withheld::standard(), Suites::listed('Unit'));
    $pest->listing(Withheld::standard(), Suites::listed('Process'));

    expect($shell->commands())->toHaveCount(2);
});

it('lists several sets of suites side by side, each into a file of its own, as it lists each alone, and each set once', function (): void {
    $at = PestCases::project();
    $alone = new ShellFake(static fn(Command $command): Ran => PestCases::listed($command));
    $together = new ShellFake(static fn(Command $command): Ran => PestCases::listed($command));
    $pest = new Pest($at, $together, Patching::off(), new CapDirectory(), new PackageDirectory(), Triage::standard()->limits()->bounds());
    $one = new Pest(PestCases::project(), $alone, Patching::off(), new CapDirectory(), new PackageDirectory(), Triage::standard()->limits()->bounds());
    $listed = $pest->listingsStarted(Withheld::standard(), Suites::listed('Unit'), Suites::listed('Process'))->taken();
    $again = $pest->listingsStarted(Withheld::standard(), Suites::listed('Process'), Suites::listed('Unit'))->taken();

    expect($listed)->toEqual([
        $one->listing(Withheld::standard(), Suites::listed('Unit')),
        $one->listing(Withheld::standard(), Suites::listed('Process')),
    ])->and($again)->toEqual([$listed[1], $listed[0]])
        ->and($together->batches())->toBe([2])
        ->and($together->commands())->toEqual([
            PestCases::invocation($at)->listing(Withheld::standard(), Suites::listed('Unit'), PestCases::listingFile($at)),
            PestCases::invocation($at)->listing(
                Withheld::standard(),
                Suites::listed('Process'),
                sprintf('%s/tests-2.xml', dirname(PestCases::listingFile($at))),
            ),
        ]);
});

it('says why it lists a set of suites side by side where an earlier list cannot be removed, and lists the others', function (): void {
    $at = PestCases::project();
    mkdir(PestCases::listingFile($at), recursive: true);
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::listed($command));
    $pest = new Pest($at, $shell, Patching::off(), new CapDirectory(), new PackageDirectory(), Triage::standard()->limits()->bounds());
    $listed = $pest->listingsStarted(Withheld::standard(), Suites::listed('Unit'), Suites::listed('Process'))->taken();

    $blocked = PestCases::project();
    $beside = sprintf('%s/tests-2.xml', dirname(PestCases::listingFile($blocked)));
    mkdir(PestCases::listingFile($blocked), recursive: true);
    mkdir($beside, recursive: true);
    $untouched = new ShellFake(static fn(Command $command): Ran => PestCases::listed($command));
    $none = new Pest($blocked, $untouched, Patching::off(), new CapDirectory(), new PackageDirectory(), Triage::standard()->limits()->bounds())
        ->listingsStarted(Withheld::standard(), Suites::listed('Unit'), Suites::listed('Process'))
        ->taken();

    expect($listed[0])->toEqual(Leftover::at(PestCases::listingFile($at)))
        ->and($listed[1])->toBeInstanceOf(TestListing::class)
        ->and($shell->batches())->toBe([1])
        ->and($none)->toEqual([Leftover::at(PestCases::listingFile($blocked)), Leftover::at($beside)])
        ->and($untouched->batches())->toBe([]);
});

it('cannot list the tests where Pest fails, or where an earlier list cannot be removed', function (): void {
    $at = PestCases::project();
    $failed = ShellFake::answering(Ran::finished(succeeded: false, output: 'broken'));
    $pest = new Pest($at, $failed, Patching::off(), new CapDirectory(), new PackageDirectory(), Triage::standard()->limits()->bounds());
    $blocked = PestCases::project();
    mkdir(PestCases::listingFile($blocked), recursive: true);
    $untouched = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect($pest->listing(Withheld::standard(), Suites::all()))->toEqual(CannotJudge::because(sprintf(
        "Pest did not list the tests of the suite: %s holds no list of tests. It said:\nbroken",
        PestCases::listingFile($at),
    )))
        ->and(new Pest($blocked, $untouched, Patching::off(), new CapDirectory(), new PackageDirectory(), Triage::standard()->limits()->bounds())->listing(Withheld::standard(), Suites::all()))
        ->toEqual(Leftover::at(PestCases::listingFile($blocked)))
        ->and($untouched->commands())->toBe([]);
});
