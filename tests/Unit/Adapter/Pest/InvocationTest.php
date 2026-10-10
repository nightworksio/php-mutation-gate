<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Bridges;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\PcovReach;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus as AcmePlusToMinus;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;
use Pest\Mutate\Mutators\Logical\TrueToFalse;

/** Pest's command lines where Composer installs packages in `vendor`. */
function invocation(): Invocation
{
    return Invocation::installedIn(Path::of('vendor'));
}

/** Where pcov collects in a project at `/p` that installs packages in `vendor`. */
function reach(): PcovReach
{
    return PcovReach::under('/p', Path::of('vendor'));
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
    $request = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'))
        ->across(ProcessCount::of(4));

    expect(invocation()->coverage($request, '/p/.mutation-gate/coverage', reach()))->toEqual(Command::php(
        Withheld::standard(),
        '-d',
        'pcov.directory=/p',
        '-d',
        'pcov.exclude=~^/p/vendor/~',
        'vendor/pestphp/pest/bin/pest',
        '--parallel',
        '--processes=4',
        "--passthru-php='-d' 'pcov.directory=/p' '-d' 'pcov.exclude=~^/p/vendor/~'",
        '--no-tia',
        '--coverage-php=/p/.mutation-gate/coverage/coverage.php',
        '--log-junit=/p/.mutation-gate/coverage/junit.xml',
    ));
});

it('runs one group under coverage', function (): void {
    $request = CoverageRun::of(Group::named('holds:src/Held.php'), Path::of('held'));

    expect(invocation()->coverage($request, '/p/held', reach())->arguments())->toBe([
        PHP_BINARY,
        '-d',
        'pcov.directory=/p',
        '-d',
        'pcov.exclude=~^/p/vendor/~',
        'vendor/pestphp/pest/bin/pest',
        '--parallel',
        '--processes=1',
        "--passthru-php='-d' 'pcov.directory=/p' '-d' 'pcov.exclude=~^/p/vendor/~'",
        '--no-tia',
        '--coverage-php=/p/held/coverage.php',
        '--log-junit=/p/held/junit.xml',
        '--group=holds:src/Held.php',
        '--do-not-fail-on-empty-test-suite',
    ]);
});

it('hands every process paratest starts the settings pcov collects with, each quoted for the shell paratest reads them through', function (): void {
    $request = CoverageRun::of(WholeSuite::tests(), Path::of('c'));
    $arguments = invocation()->coverage($request, '/p/c', PcovReach::under("/it's here", Path::of('vendor')))->arguments();

    expect($arguments)->toContain(
        "--passthru-php='-d' 'pcov.directory=/it'\\''s here' '-d' 'pcov.exclude=~^/it'\\''s here/vendor/~'",
    );
});

it('runs several test files under coverage in Pest\'s own process, in place of the suite, as paratest takes one path', function (): void {
    $files = TestPaths::of(Paths::of(Path::of('tests/MoneySpec.php'), Path::of('tests/Unit/HeldSpec.php')));
    $arguments = invocation()->coverage(CoverageRun::of($files, Path::of('held'))->across(ProcessCount::of(4)), '/p/held', reach())->arguments();

    expect(array_slice($arguments, -3))->toBe([
        'tests/MoneySpec.php',
        'tests/Unit/HeldSpec.php',
        '--do-not-fail-on-empty-test-suite',
    ])->and(array_filter($arguments, static fn(string $argument): bool => str_starts_with($argument, '--p')))->toBe([]);
});

it('runs one test file under coverage across its processes', function (): void {
    $file = TestPaths::of(Paths::of(Path::of('tests/MoneySpec.php')));
    $arguments = invocation()->coverage(CoverageRun::of($file, Path::of('held'))->across(ProcessCount::of(4)), '/p/held', reach())->arguments();

    expect($arguments)->toContain('--parallel', '--processes=4')
        ->and(array_slice($arguments, -2))->toBe(['tests/MoneySpec.php', '--do-not-fail-on-empty-test-suite']);
});

it('runs the tests a filter names under coverage', function (): void {
    $request = CoverageRun::of(Filter::matching('HeldTest'), Path::of('held'));

    expect(array_slice(invocation()->coverage($request, '/p/held', reach())->arguments(), -2))->toBe([
        '--filter=HeldTest',
        '--do-not-fail-on-empty-test-suite',
    ]);
});

it('starts a run of no test as pest-plugin-mutate starts a mutant\'s own run, in the environment it gives one', function (): void {
    $command = invocation()->startingUp(Withheld::of('DEPLOY_*'), '/p/src/Money.php', '/p/.gate/Money.php');

    expect($command->arguments())->toBe([
        PHP_BINARY,
        'vendor/pestphp/pest/bin/pest',
        '--no-tia',
        '--bail',
        '--colors=never',
        '--filter=(?!)',
        '--do-not-fail-on-empty-test-suite',
    ])->and(array_intersect_key($command->environment(), array_flip([
        'PEST_MUTATION_TESTING',
        'PEST_MUTATION_FILE',
        'PARATEST',
        'TEST_TOKEN',
        'UNIQUE_TEST_TOKEN',
        'LARAVEL_PARALLEL_TESTING',
    ])))->toEqual([
        'PEST_MUTATION_TESTING' => '/p/src/Money.php',
        'PEST_MUTATION_FILE' => '/p/.gate/Money.php',
        'PARATEST' => '1',
        'TEST_TOKEN' => '0',
        'UNIQUE_TEST_TOKEN' => '0_start-up',
        'LARAVEL_PARALLEL_TESTING' => '1',
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
    )->with(['MUTATION_GATE_RESULTS' => '/p/results.jsonl', 'MUTATION_GATE_KILL_MATRIX' => 'first-killer']));
});

it('mutates a tree less its held paths, by a group and some mutators, by a deadline', function (): void {
    $request = MutationRequest::of(Paths::of(Path::of('src')), Group::named('holds:src'))
        ->narrowedTo(Paths::of(Path::of('src')), Narrowing::none()->toMutators(Mutators::named(
            PlusToMinus::class,
            TrueToFalse::class,
        )))
        ->leavingOut(Paths::of(Path::of('src/Kernel.php'), Path::of('src/Boot')))
        ->across(Pool::of(ProcessCount::of(8), Workers::Fork))
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
        '--do-not-fail-on-empty-test-suite',
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
            CoverageRun::of(WholeSuite::tests(), Path::of('c'))->withholding(Withheld::of('CI_JOB_TOKEN')),
            '/p/c',
            reach(),
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
        '--do-not-fail-on-empty-test-suite',
        'tests/MoneySpec.php',
        'tests/Unit/TaxSpec.php',
    ]);
});

it('puts the options a judging run is given before its files', function (): void {
    $tests = Paths::of(Path::of('tests/MoneySpec.php'));

    expect(invocation()->judging($tests, WholeSuite::tests(), Withheld::nothing(), '--log-junit=/r/junit.xml')->arguments())->toBe([
        PHP_BINARY,
        'vendor/pestphp/pest/bin/pest',
        '--no-tia',
        '--bail',
        '--colors=never',
        '--log-junit=/r/junit.xml',
        'tests/MoneySpec.php',
    ]);
});

it('names Pest\'s default set beside the bridges for a run of every mutator, and a bridged mutator by its bridge', function (): void {
    $bridges = Bridges::to(Enabled::of(MutatorSet::of(AcmePlusToMinus::class)));
    $bridge = 'NightWorksIO\\MutationGateBridge\\Pest\\NightWorksIO\\MutationGate\\Tests\\Support\\Mutators\\PlusToMinus';
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests());
    $every = invocation()->mutation($request, WholeSuite::tests(), '/p/r.jsonl', $bridges)->arguments();
    $some = invocation()->mutation(
        $request->narrowedTo(Paths::of(Path::of('src')), Narrowing::none()->toMutators(Mutators::named('acme/PlusToMinus', TrueToFalse::class))),
        WholeSuite::tests(),
        '/p/r.jsonl',
        $bridges,
    )->arguments();

    expect($every)->toContain(sprintf('--mutator=Pest\\Mutate\\Mutators\\Sets\\DefaultSet,%s', $bridge))
        ->and($some)->toContain(sprintf('--mutator=%s,%s', $bridge, TrueToFalse::class))
        ->and(invocation()->mutation($request, WholeSuite::tests(), '/p/r.jsonl')->arguments())
        ->not->toContain(sprintf('--mutator=Pest\\Mutate\\Mutators\\Sets\\DefaultSet,%s', $bridge));
});

it('keeps the coverage run and the mutation run to one suite where the run names one', function (): void {
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests());
    $suited = $request->narrowedTo($request->files(), Narrowing::none()->toSuite(SuiteName::of('unit')));
    $run = CoverageRun::of(WholeSuite::tests(), Path::of('cov'));

    expect(invocation()->mutation($suited, WholeSuite::tests(), '/p/results.jsonl')->arguments())->toContain('--testsuite=unit')
        ->and(invocation()->mutation($request, WholeSuite::tests(), '/p/results.jsonl')->arguments())
        ->not->toContain('--testsuite=unit')
        ->and(array_slice(invocation()->coverage($run->inSuite(SuiteName::of('unit')), '/p/cov', reach())->arguments(), -1))
        ->toBe(['--testsuite=unit'])
        ->and(invocation()->coverage($run, '/p/cov', reach())->arguments())->not->toContain('--testsuite=unit');
});
