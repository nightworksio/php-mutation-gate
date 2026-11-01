<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Testo\GateConfig;
use NightWorksIO\MutationGate\Adapter\Testo\GatePlugin;
use NightWorksIO\MutationGate\Adapter\Testo\Told;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\Native\Guard;
use NightWorksIO\MutationGate\Core\Native\Outcome;
use NightWorksIO\MutationGate\Core\Native\TestTimes;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Testo\Application\Application;
use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;
use Testo\Core\Value\Status;

afterEach(function (): void {
    putenv(Variable::Results->value);
    putenv(Variable::Guard->value);
    Scratch::sweep();
});

/**
 * A suite of one case in a scratch directory, under a namespace of its own:
 * a test in a group, one that fails, one after it, and the holding group its
 * class is in.
 */
$suite = static function (): array {
    $root = Scratch::directory();
    $namespace = sprintf('GateTesto%s', hash('xxh3', $root));
    Scratch::write($root, 'tests/RunTest.php', sprintf(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace %s;

        use NightWorksIO\MutationGate\Attribute\Holds;
        use Testo\Assert;
        use Testo\Filter\Group;
        use Testo\Test;

        #[Holds('src/Money.php')]
        final class RunTest
        {
            #[Test]
            #[Group('slow')]
            public function passes(): void
            {
                Assert::true(true);
            }

            #[Test]
            public function fails(): void
            {
                Assert::true(false);
            }

            #[Test]
            public function later(): void
            {
                Assert::true(true);
            }
        }
        PHP, $namespace));

    return [$root, sprintf('%s\RunTest', $namespace)];
};

/** The suite run in this process with the gate's plugin, told this, its results recorded where `$results` says. */
$run = static function (string $root, Told $told, string $results = ''): Status {
    putenv(sprintf('%s=%s', Variable::Results->value, $results));
    $config = new ApplicationConfig(suites: [new SuiteConfig(name: 'unit', location: [sprintf('%s/tests', $root)])]);

    return Application::createFromConfig(GateConfig::wrapping($config, $told))->run()->status;
};

it('runs only the tests it selects, records each as it starts and as it ended, and times each', function () use ($suite, $run): void {
    [$root, $class] = $suite();
    Scratch::write($root, 'tests.txt', sprintf("%s::passes\n%s::fails\n%s::later\n", $class, $class, $class));
    $results = sprintf('%s/results.txt', $root);
    $times = sprintf('%s/times.txt', $root);

    $status = $run($root, Told::from([sprintf('--mutation-gate-tests=%s/tests.txt', $root), sprintf('--mutation-gate-times=%s', $times)]), $results);
    $timed = TestTimes::read((string) file_get_contents($times));

    expect($status)->toBe(Status::Failed)
        ->and(file_get_contents($results))->toBe(implode('', [
            Outcome::Started->line(sprintf('%s::passes', $class)),
            Outcome::Passed->line(sprintf('%s::passes', $class)),
            Outcome::Started->line(sprintf('%s::fails', $class)),
            Outcome::Failed->line(sprintf('%s::fails', $class)),
            Outcome::Started->line(sprintf('%s::later', $class)),
            Outcome::Passed->line(sprintf('%s::later', $class)),
        ]))
        ->and(array_map(static fn(TimedTest $test): string => $test->test()->value(), $timed))
        ->toBe([sprintf('%s::passes', $class), sprintf('%s::fails', $class), sprintf('%s::later', $class)])
        ->and(array_filter($timed, static fn(TimedTest $test): bool => $test->seconds()->outlasts(Seconds::of(10.0))))
        ->toBe([]);
});

it('stops after the first test that fails where the run stops, and runs none it does not select', function () use ($suite, $run): void {
    [$root, $class] = $suite();
    Scratch::write($root, 'tests.txt', sprintf("%s::fails\n%s::later\n", $class, $class));
    $results = sprintf('%s/results.txt', $root);

    $run($root, Told::from([sprintf('--mutation-gate-tests=%s/tests.txt', $root), '--mutation-gate-stop=after-the-first-failure']), $results);

    expect(file_get_contents($results))->toBe(implode('', [
        Outcome::Started->line(sprintf('%s::fails', $class)),
        Outcome::Failed->line(sprintf('%s::fails', $class)),
    ]));
});

it('runs only the tests in the group that judges the unit', function () use ($suite, $run): void {
    [$root, $class] = $suite();
    $results = sprintf('%s/results.txt', $root);

    $status = $run($root, Told::from(['--mutation-gate-group=slow']), $results);

    expect($status)->toBe(Status::Passed)
        ->and(file_get_contents($results))->toBe(implode('', [
            Outcome::Started->line(sprintf('%s::passes', $class)),
            Outcome::Passed->line(sprintf('%s::passes', $class)),
        ]));
});

it('runs as told, recording to no file, where the results file it is named is empty', function () use ($suite, $run): void {
    [$root] = $suite();

    expect($run($root, Told::from(['--mutation-gate-group=slow'])))->toBe(Status::Passed);
});

it('says in the guard file the run names whether opcache could serve a cached original', function () use ($suite, $run): void {
    [$root] = $suite();
    $guard = sprintf('%s/guard.txt', $root);
    putenv(sprintf('%s=%s', Variable::Guard->value, $guard));

    $run($root, Told::from(['--mutation-gate-group=slow']));

    expect(file_get_contents($guard))->toBe(Guard::noting(Opcache::ofCommandLine(ini_get(Opcache::CLI))));
});

it('lists every test and the groups each is in, running none and recording none', function () use ($suite, $run): void {
    [$root, $class] = $suite();
    $results = sprintf('%s/results.txt', $root);
    $listing = sprintf('%s/listing.json', $root);

    $run($root, Told::from([sprintf('--mutation-gate-listing=%s', $listing)]), $results);

    expect(json_decode((string) file_get_contents($listing), associative: true))->toBe([
        'listed' => [sprintf('%s::passes', $class), sprintf('%s::fails', $class), sprintf('%s::later', $class)],
        'groups' => [
            'slow' => [sprintf('%s::passes', $class)],
            'holds:src/Money.php' => [sprintf('%s::passes', $class), sprintf('%s::fails', $class), sprintf('%s::later', $class)],
        ],
    ])
        ->and(is_file($results))->toBeFalse();
});

it('records a test that throws as errored', function () use ($run): void {
    $root = Scratch::directory();
    $namespace = sprintf('BrokenTesto%s', hash('xxh3', $root));
    Scratch::write($root, 'tests/BrokenTest.php', sprintf(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace %s;

        use Testo\Test;

        final class BrokenTest
        {
            #[Test]
            public function breaks(): void
            {
                throw new \LogicException('It breaks.');
            }
        }
        PHP, $namespace));
    $results = sprintf('%s/results.txt', $root);

    $run($root, Told::from([]), $results);

    expect(file_get_contents($results))->toBe(implode('', [
        Outcome::Started->line(sprintf('%s\BrokenTest::breaks', $namespace)),
        Outcome::Errored->line(sprintf('%s\BrokenTest::breaks', $namespace)),
    ]));
});

it('configures nothing in a container it does not know, noting nothing in the guard file', function (): void {
    $guard = sprintf('%s/guard.txt', Scratch::directory());
    putenv(sprintf('%s=%s', Variable::Guard->value, $guard));

    new GatePlugin(Told::from([]))->configure(new stdClass());

    expect(is_file($guard))->toBeFalse();
});

it('appends to the guard file whether opcache could serve a cached original, and notes nothing where none is named', function (): void {
    $root = Scratch::directory();
    $guard = sprintf('%s/guard.txt', $root);

    GatePlugin::noting($guard, Opcache::ofCommandLine('0'));
    GatePlugin::noting($guard, Opcache::ofCommandLine('1'));
    GatePlugin::noting('', Opcache::ofCommandLine('0'));
    GatePlugin::noting(file: false, opcache: Opcache::ofCommandLine('0'));

    expect(file_get_contents($guard))
        ->toBe(sprintf('%s%s', Guard::noting(Opcache::ofCommandLine('0')), Guard::noting(Opcache::ofCommandLine('1'))));
});
