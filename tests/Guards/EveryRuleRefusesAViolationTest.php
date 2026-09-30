<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Fixture;
use NightWorksIO\MutationGate\Tests\Support\Fixtures;
use NightWorksIO\MutationGate\Tests\Support\Proof;
use NightWorksIO\MutationGate\Tests\Support\Rules;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Process\Process;

// R2: every rule refuses a planted violation of itself.
//
// A rule can carry its identifier, run on every commit and check nothing: a
// namespace that resolves to no files, a path scope that misses the directory it
// was meant for, a message nobody wired up. So every violation in
// Tests\Support\Fixtures is planted in a throwaway copy of the checkout, each
// machine runs once over the copy, and every rule has to report its own fixture
// by name. The checkout itself is only read. A copy a killed run left behind is
// swept at the start of the next run.

beforeAll(function (): void {
    sweepAbandonedCopies();
});

afterAll(function (): void {
    discardTheRun();
});

it('has a fixture for every rule the architecture documents', function (): void {
    $missing = rulesWithNoFixture(
        Rules::documented(),
        array_map(static fn(Fixture $fixture): string => $fixture->rule, Fixtures::all()),
    );

    expect($missing)->toBe([], sprintf(
        "These rules have never been shown to refuse anything:\n  %s\n\nAdd the smallest violation to Tests\\Support\\Fixtures, or say with Fixture::notDrivable() why no snippet can break it.",
        implode("\n  ", $missing),
    ));
});

it('names a rule that nothing is planted under', function (): void {
    // R2
    expect(rulesWithNoFixture(['A1' => 'arch', 'A3' => 'arch'], ['A1']))->toBe(['A3'])
        ->and(rulesWithNoFixture(['A1' => 'arch'], ['A1']))->toBe([]);
});

it('gives a reason for every rule that has no file to plant', function (): void {
    $silent = array_values(array_filter(
        Fixtures::all(),
        static fn(Fixture $fixture): bool => in_array($fixture->proof, [Proof::Direct, Proof::NotDrivable], strict: true) && trim($fixture->marker) === '',
    ));

    expect($silent)->toBe([]);
});

it('plants nothing in the checkout it copied', function (): void {
    $copy = theRun()['copy'];
    $missing = [];
    $leaked = [];

    foreach (Fixtures::all() as $fixture) {
        if (! $fixture->proof->isAFileOfItsOwn()) {
            continue;
        }

        if (! is_file(sprintf('%s/%s', $copy, $fixture->path))) {
            $missing[] = $fixture->path;
        }

        if (is_file(Tree::at($fixture->path))) {
            $leaked[] = $fixture->path;
        }
    }

    expect($missing)->toBe([])
        ->and($leaked)->toBe([]);
});

it('hears the analyser refuse every violation planted for it', function (): void {
    $run = theRun();
    $reported = analyserFindings($run['analyser'], $run['copy']);

    expect($reported)->not->toBe([], sprintf(
        "The analyser reported nothing readable, so no rule was measured. What it wrote to stderr:\n\n%s",
        $run['analyser']->getErrorOutput(),
    ));

    $silent = [];

    foreach (fixturesProvenBy(Proof::Analyser) as $fixture) {
        $messages = array_key_exists($fixture->path, $reported) ? $reported[$fixture->path] : [];

        if (! array_any($messages, static fn(string $message): bool => str_contains($message, $fixture->marker))) {
            $silent[] = sprintf('%s: %s did not produce "%s"', $fixture->rule, $fixture->path, $fixture->marker);
        }
    }

    expect($silent)->toBe([], sprintf(
        "The analyser read these violations and said nothing:\n  %s",
        implode("\n  ", $silent),
    ));
});

it('sees the suite fail every violation planted for it, naming the fixture', function (): void {
    $run = theRun();
    $failures = suiteFailures($run['suite'], sprintf('%s.junit.xml', $run['copy']));
    $silent = [];

    foreach (Fixtures::all() as $fixture) {
        if (! $fixture->proof->readBySuite()) {
            continue;
        }

        $refused = array_any(
            $failures,
            static fn(string $report, string $name): bool => str_contains($name, $fixture->marker) && str_contains($report, $fixture->evidence),
        );

        if (! $refused) {
            $silent[] = sprintf('%s: "%s" did not fail naming %s', $fixture->rule, $fixture->marker, $fixture->evidence);
        }
    }

    expect($silent)->toBe([], sprintf(
        "The suite read these violations and stayed green:\n  %s\n\nA rule has to fail and name the fixture that broke it.",
        implode("\n  ", $silent),
    ));
});

it('hears the dependency analyser refuse every dependency planted for it', function (): void {
    $run = theRun();
    $run['dependencies']->wait();
    $said = $run['dependencies']->getOutput();
    $silent = [];

    foreach (fixturesProvenBy(Proof::Dependencies) as $fixture) {
        if (! str_contains($said, basename($fixture->path))) {
            $silent[] = sprintf('%s: %s', $fixture->rule, $fixture->path);
        }
    }

    expect($silent)->toBe([], sprintf("The dependency analyser did not name these:\n  %s\n\nIt said:\n%s", implode("\n  ", $silent), $said));
});

// The two below run in CI only, as the `ci-only` group phpunit.xml leaves out
// by default: the audit asks Packagist for its advisories, and the coverage
// floor runs the whole covered suite once more. CI's guards job runs them, and
// there a missing answer fails, as any other would.

it('hears composer audit refuse every advisory planted for it, in a copy of its own', function (): void {
    $copy = aCopy();

    foreach (fixturesProvenBy(Proof::Audit) as $fixture) {
        plantEdit($copy, $fixture);
    }

    $audit = new Process(['composer', 'audit', '--locked', '--format=json', '--no-interaction'], $copy, timeout: 300);
    $audit->run();
    $said = $audit->getOutput();
    $silent = [];

    foreach (fixturesProvenBy(Proof::Audit) as $fixture) {
        if (! str_contains($said, $fixture->evidence)) {
            $silent[] = sprintf('%s: %s', $fixture->rule, $fixture->evidence);
        }
    }

    expect($audit->isSuccessful())->toBeFalse()
        ->and($silent)->toBe([], sprintf(
            "composer audit did not report these:\n  %s\n\nIt said:\n%s\n%s",
            implode("\n  ", $silent),
            $said,
            $audit->getErrorOutput(),
        ));
})->group('ci-only');

it('sees the coverage floor refuse every uncovered line planted for it, alone in a copy of its own', function (): void {
    $copy = aCopy();

    foreach (fixturesProvenBy(Proof::Coverage) as $fixture) {
        plantFile(sprintf('%s/%s', $copy, $fixture->path), $fixture->code);
    }

    $covered = new Process(['sh', 'scripts/coverage.sh'], $copy, timeout: null);
    $covered->run();
    $said = sprintf('%s%s', $covered->getOutput(), $covered->getErrorOutput());
    $silent = [];

    foreach (fixturesProvenBy(Proof::Coverage) as $fixture) {
        if (preg_match(sprintf('#%s\b[^\n]*?(\d+(?:\.\d+)?)\s?%%#', preg_quote($fixture->evidence, '#')), $said, $listed) !== 1 || $listed[1] === '100.0') {
            $silent[] = sprintf('%s: %s is not listed under 100%%', $fixture->rule, $fixture->evidence);
        }
    }

    expect($covered->isSuccessful())->toBeFalse()
        ->and($said)->toContain('Code coverage below expected')
        ->and($silent)->toBe([], sprintf("The covered run did not count these:\n  %s\n\nIt said:\n%s", implode("\n  ", $silent), $said));
})->group('ci-only');

/**
 * The documented rules nothing is planted under.
 *
 * @param  array<string, string> $documented rule => claimed enforcement
 * @param  list<string>          $covered    the rules something is planted under
 * @return list<string>
 */
function rulesWithNoFixture(array $documented, array $covered): array
{
    return array_values(array_filter(
        array_map(strval(...), array_keys($documented)),
        static fn(string $rule): bool => ! in_array($rule, $covered, strict: true),
    ));
}

/** @return list<Fixture> */
function fixturesProvenBy(Proof $proof): array
{
    return array_values(array_filter(Fixtures::all(), static fn(Fixture $fixture): bool => $fixture->proof === $proof));
}

/**
 * Every message the analyser reported, by the path it was reported against,
 * relative to the copy.
 *
 * @return array<string, list<string>>
 */
function analyserFindings(Process $analyser, string $copy): array
{
    $analyser->wait();
    $found = [];

    foreach (listUnder(json_decode($analyser->getOutput(), associative: true), 'files') as $path => $file) {
        $found[str_replace(sprintf('%s/', $copy), '', (string) $path)] = array_map(
            static fn(mixed $message): string => is_array($message) ? sprintf('%s %s', textOf($message, 'message'), textOf($message, 'identifier')) : '',
            array_values(listUnder($file, 'messages')),
        );
    }

    return $found;
}

/** @return array<mixed> */
function listUnder(mixed $decoded, string $key): array
{
    return is_array($decoded) && array_key_exists($key, $decoded) && is_array($decoded[$key]) ? $decoded[$key] : [];
}

/** @param array<mixed> $entry */
function textOf(array $entry, string $key): string
{
    return array_key_exists($key, $entry) && is_string($entry[$key]) ? $entry[$key] : '';
}

/**
 * Every failing test in a JUnit report, by its name, with everything reported
 * about it.
 *
 * @return array<string, string>
 */
function suiteFailures(Process $suite, string $report): array
{
    $suite->wait();
    $xml = is_file($report) ? simplexml_load_file($report) : false;

    if ($xml === false) {
        return [];
    }

    $found = [];

    foreach ($xml->xpath('//testcase[failure or error]') ?? [] as $case) {
        $found[(string) $case['name']] = sprintf('%s %s %s', (string) $case['class'], (string) $case->failure, (string) $case->error);
    }

    return $found;
}

/**
 * The planted copy and the three machines reading it, started once and shared
 * by the tests that read them.
 *
 * @return array{copy: string, analyser: Process, suite: Process, dependencies: Process}
 */
function theRun(): array
{
    /** @var array{copy: string, analyser: Process, suite: Process, dependencies: Process}|null $run */
    static $run = null;

    if ($run !== null) {
        return $run;
    }

    $copy = aCopy();
    plantEverything($copy);

    $run = [
        'copy' => $copy,
        'analyser' => started(new Process(
            [PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress', '--memory-limit=1G', ...array_map(
                static fn(Fixture $fixture): string => sprintf('%s/%s', $copy, $fixture->path),
                fixturesProvenBy(Proof::Analyser),
            )],
            $copy,
            timeout: null,
        )),
        'suite' => started(new Process(
            [PHP_BINARY, 'vendor/bin/pest', '--testsuite=Arch', sprintf('--log-junit=%s.junit.xml', $copy)],
            $copy,
            timeout: null,
        )),
        'dependencies' => started(new Process(
            [PHP_BINARY, 'vendor/bin/composer-dependency-analyser', '--show-all-usages'],
            $copy,
            timeout: null,
        )),
    ];

    return $run;
}

function started(Process $process): Process
{
    $process->start();
    runningProcesses()->append($process);

    return $process;
}

/** @return ArrayObject<int, Process> */
function runningProcesses(): ArrayObject
{
    /** @var ArrayObject<int, Process> $running */
    static $running = new ArrayObject();

    return $running;
}

/**
 * The lock held on every copy this run made, by the copy's path. The kernel
 * releases a lock with the process that held it, which is how the sweep tells a
 * copy a killed run left from one a live run is reading.
 *
 * @return ArrayObject<string, resource>
 */
function heldLocks(): ArrayObject
{
    /** @var ArrayObject<string, resource> $held */
    static $held = new ArrayObject();

    return $held;
}

/**
 * Where the copies are made: under the system's temporary directory, resolved,
 * because on macOS it is reached through a symlink and the analyser reports the
 * resolved path.
 */
function whereCopiesAreMade(): string
{
    $temporary = realpath(sys_get_temp_dir());

    return sprintf('%s/mutation-gate-guards', $temporary === false ? sys_get_temp_dir() : $temporary);
}

/**
 * A fresh copy of the working tree, every tracked and unignored file as it is on
 * disk, with `vendor` copied whole: the autoloader and Pest find the project from
 * where their own files sit, so a linked vendor would lead them back here.
 */
function aCopy(): string
{
    if (! is_dir(whereCopiesAreMade())) {
        mkdir(whereCopiesAreMade(), 0o755, recursive: true);
    }

    $copy = sprintf('%s/%s', whereCopiesAreMade(), sodium_bin2hex(random_bytes(8)));
    lockTheCopy($copy);

    $listed = (string) shell_exec(sprintf('git -C %s ls-files -z --cached --others --exclude-standard', escapeshellarg(Tree::root())));

    foreach (array_filter(explode("\0", $listed), static fn(string $path): bool => $path !== '' && is_file(Tree::at($path))) as $path) {
        plantFile(sprintf('%s/%s', $copy, $path), (string) file_get_contents(Tree::at($path)), exactly: true);
    }

    shell_exec(sprintf('cp -R%s %s %s', PHP_OS_FAMILY === 'Darwin' ? 'c' : '', escapeshellarg(Tree::at('vendor')), escapeshellarg(sprintf('%s/vendor', $copy))));

    if (! is_file(sprintf('%s/vendor/autoload.php', $copy))) {
        throw new RuntimeException(sprintf('vendor was not copied into %s.', $copy));
    }

    return $copy;
}

function lockTheCopy(string $copy): void
{
    $lock = fopen(sprintf('%s.lock', $copy), 'c');

    if ($lock === false || ! flock($lock, LOCK_EX)) {
        throw new RuntimeException(sprintf('Could not lock %s for this run.', $copy));
    }

    heldLocks()[$copy] = $lock;
}

/** Remove every copy no live run holds the lock of. */
function sweepAbandonedCopies(): void
{
    $locks = glob(sprintf('%s/*.lock', whereCopiesAreMade()));

    foreach ($locks === false ? [] : $locks as $path) {
        $lock = fopen($path, 'c');

        if ($lock === false) {
            continue;
        }

        if (flock($lock, LOCK_EX | LOCK_NB)) {
            $copy = substr($path, 0, -strlen('.lock'));
            shell_exec(sprintf('rm -rf %s %s', escapeshellarg($copy), escapeshellarg(sprintf('%s.junit.xml', $copy))));
            unlink($path);
        }

        fclose($lock);
    }
}

/** Stop whatever is still running, and remove every copy this run made. */
function discardTheRun(): void
{
    foreach (runningProcesses() as $process) {
        $process->stop(0);
    }

    foreach (heldLocks() as $copy => $lock) {
        shell_exec(sprintf('rm -rf %s %s %s', escapeshellarg($copy), escapeshellarg(sprintf('%s.junit.xml', $copy)), escapeshellarg(sprintf('%s.lock', $copy))));
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Every fixture planted in a copy: its own file, or its edit of a file the repository owns. */
function plantEverything(string $copy): void
{
    foreach (Fixtures::all() as $fixture) {
        if ($fixture->proof->isAFileOfItsOwn()) {
            plantFile(sprintf('%s/%s', $copy, $fixture->path), $fixture->code);
        }

        if ($fixture->proof === Proof::Edit) {
            plantEdit($copy, $fixture);
        }
    }
}

/**
 * A fixture's change to a file the repository owns. What it replaces has to
 * appear exactly once, or the rule would be proven against a tree nothing was
 * planted in.
 */
function plantEdit(string $copy, Fixture $fixture): void
{
    $path = sprintf('%s/%s', $copy, $fixture->path);
    $was = (string) file_get_contents($path);
    $found = substr_count($was, $fixture->replacing);

    if ($found !== 1) {
        throw new RuntimeException(sprintf('The fixture for %s replaces text that appears %d times in %s:%s%s', $fixture->rule, $found, $fixture->path, PHP_EOL, $fixture->replacing));
    }

    plantFile($path, str_replace($fixture->replacing, $fixture->code, $was), exactly: true);
}

function plantFile(string $path, string $code, bool $exactly = false): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o755, recursive: true);
    }

    file_put_contents($path, $exactly ? $code : sprintf("%s\n", trim($code)));
}
