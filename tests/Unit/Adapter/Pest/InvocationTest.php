<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\CoverageRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;
use Pest\Mutate\Mutators\Logical\TrueToFalse;

/** Pest's command lines where Composer installs packages in `vendor`. */
function invocation(): Invocation
{
    return Invocation::installedIn(Path::of('vendor'));
}

it('lists the groups without colour', function (): void {
    expect(invocation()->listingGroups(Withheld::standard()))
        ->toEqual(Command::pest('vendor/pestphp/pest/bin/pest', Withheld::standard(), '--list-groups', '--colors=never'));
});

it('runs Pest\'s own script in the vendor directory the project installs into', function (): void {
    expect(Invocation::installedIn(Path::of('lib/vendor'))->listingGroups(Withheld::standard()))
        ->toEqual(Command::pest('lib/vendor/pestphp/pest/bin/pest', Withheld::standard(), '--list-groups', '--colors=never'));
});

it('runs the whole suite under coverage into a directory, as --coverage expects to find it', function (): void {
    $request = CoverageRequest::running(WholeSuite::tests(), Path::of('.mutation-gate/coverage'))
        ->across(Processes::of(4));

    expect(invocation()->coverage($request, '/p/.mutation-gate/coverage'))->toEqual(Command::pest(
        'vendor/pestphp/pest/bin/pest',
        Withheld::standard(),
        '--parallel',
        '--processes=4',
        '--no-tia',
        '--coverage-php=/p/.mutation-gate/coverage/coverage.php',
        '--log-junit=/p/.mutation-gate/coverage/junit.xml',
    ));
});

it('runs one group under coverage', function (): void {
    $request = CoverageRequest::running(Group::named('holds:src/Held.php'), Path::of('held'));

    expect(invocation()->coverage($request, '/p/held')->arguments())->toBe([
        PHP_BINARY,
        'vendor/pestphp/pest/bin/pest',
        '--parallel',
        '--processes=1',
        '--no-tia',
        '--coverage-php=/p/held/coverage.php',
        '--log-junit=/p/held/junit.xml',
        '--group=holds:src/Held.php',
    ]);
});

it('mutates some files against the whole suite, over the project\'s own config, with no deadline', function (): void {
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php'), Path::of('src/Held.php')), WholeSuite::tests());

    expect(invocation()->mutation($request, WholeSuite::tests(), '/p/results.jsonl'))->toEqual(Command::pest(
        'vendor/pestphp/pest/bin/pest',
        Withheld::standard(),
        '--mutate',
        '--no-cache',
        '--parallel',
        '--no-tia',
        '--everything',
        '--covered-only=false',
        '--stop-on-untested=false',
        '--stop-on-uncovered=false',
        '--retry=false',
        '--colors=never',
        '--path=src/Money.php,src/Held.php',
        '--ignore=.mutation-gate',
    )->with(['MUTATION_GATE_RESULTS' => '/p/results.jsonl']));
});

it('mutates a tree less its held paths, by a group and some mutators, by a deadline', function (): void {
    $request = MutationRequest::of(Paths::of(Path::of('src')), Group::named('holds:src'))
        ->leavingOut(Paths::of(Path::of('src/Kernel.php'), Path::of('src/Boot')))
        ->onlyMutators(Mutators::named(
            PlusToMinus::class,
            TrueToFalse::class,
        ))
        ->across(Processes::of(8))
        ->within(Seconds::of(600.0));
    $command = invocation()->mutation($request, Group::named('holds:src'), '/p/results.jsonl');

    expect($command->arguments())->toBe([
        PHP_BINARY,
        'vendor/pestphp/pest/bin/pest',
        '--mutate',
        '--no-cache',
        '--parallel',
        '--no-tia',
        '--everything',
        '--covered-only=false',
        '--stop-on-untested=false',
        '--stop-on-uncovered=false',
        '--retry=false',
        '--colors=never',
        '--path=src',
        '--ignore=src/Kernel.php,src/Boot',
        '--group=holds:src',
        '--mutator=Pest\Mutate\Mutators\Arithmetic\PlusToMinus,Pest\Mutate\Mutators\Logical\TrueToFalse',
    ])->and($command->deadline())->toEqual(Seconds::of(600.0));
});

it('leaves out one held path by name', function (): void {
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())
        ->leavingOut(Paths::of(Path::of('src/Kernel.php')));
    $command = invocation()->mutation($request, WholeSuite::tests(), '/p/r');

    expect($command->arguments())->toContain('--ignore=src/Kernel.php')
        ->and($command->deadline())->toEqual(Unlimited::time());
});

it('withholds from the listing, the coverage run and the mutation run what each is told to withhold', function (): void {
    $before = getenv('CI_JOB_TOKEN');
    putenv('CI_JOB_TOKEN=secret');

    try {
        $coverage = invocation()->coverage(
            CoverageRequest::running(WholeSuite::tests(), Path::of('c'))->withholding(Withheld::of('CI_JOB_TOKEN')),
            '/p/c',
        );
        $mutation = invocation()->mutation(
            MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())
                ->withholding(Withheld::of('CI_JOB_TOKEN')),
            WholeSuite::tests(),
            '/p/results.jsonl',
        );
        $listing = invocation()->listingGroups(Withheld::of('CI_JOB_TOKEN'));
        $unlisted = invocation()->listingGroups(Withheld::standard());
    } finally {
        putenv(is_string($before) ? sprintf('CI_JOB_TOKEN=%s', $before) : 'CI_JOB_TOKEN');
    }

    expect($coverage->environment())->toMatchArray(['CI_JOB_TOKEN' => false])
        ->and($mutation->environment())->toMatchArray(['CI_JOB_TOKEN' => false])
        ->and($listing->environment())->toMatchArray(['CI_JOB_TOKEN' => false])
        ->and($unlisted->environment())->not->toHaveKey('CI_JOB_TOKEN');
});

it('judges one mutant by some test files, one after another, stopping at the first that fails', function (): void {
    $tests = Paths::of(Path::of('tests/MoneySpec.php'), Path::of('tests/Unit/TaxSpec.php'));

    expect(invocation()->judging($tests, WholeSuite::tests(), Withheld::standard()))->toEqual(Command::pest(
        'vendor/pestphp/pest/bin/pest',
        Withheld::standard(),
        '--no-tia',
        '--bail',
        '--colors=never',
        'tests/MoneySpec.php',
        'tests/Unit/TaxSpec.php',
    ))->and(invocation()->judging($tests, Group::named('holds:src/Money.php'), Withheld::nothing())->arguments())->toBe([
        PHP_BINARY,
        'vendor/pestphp/pest/bin/pest',
        '--no-tia',
        '--bail',
        '--colors=never',
        '--group=holds:src/Money.php',
        'tests/MoneySpec.php',
        'tests/Unit/TaxSpec.php',
    ]);
});
