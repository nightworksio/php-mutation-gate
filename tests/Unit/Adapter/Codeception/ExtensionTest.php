<?php

declare(strict_types=1);

use Codeception\Events;
use NightWorksIO\MutationGate\Adapter\Codeception\Extension;
use NightWorksIO\MutationGate\Adapter\Codeception\Option;
use NightWorksIO\MutationGate\Adapter\Native\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Native\Guard;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\CoverageDriverFake;
use NightWorksIO\MutationGate\Tests\Support\CodeceptionTests;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Filter;

beforeEach(function (): void {
    GateEnvironment::clear();
});

afterEach(function (): void {
    GateEnvironment::restore();
    Scratch::sweep();
});
/**
 * The extension as Codeception builds it, told to run only the first test of HeldTest, from a file in this
 * directory, and these settings besides.
 *
 * @param array<string, string> $settings
 */
function selectingTheFirst(string $root, array $settings = []): Extension
{
    Scratch::write($root, 'selected.txt', sprintf("%s\n", CodeceptionTests::id('testIsHeldByItsCase')));

    return new Extension([Option::Selected->value => sprintf('%s/selected.txt', $root), ...$settings], []);
}

/**
 * Why Codeception is to skip a test of HeldTest as it starts under the extension, after a suite of these
 * modules; nothing where it runs it.
 *
 * @param non-empty-string $method
 */
function skipOf(Extension $extension, string $method, string ...$modules): string
{
    $extension->suite(CodeceptionTests::suite(['modules' => ['enabled' => array_values($modules)]]));
    $event = CodeceptionTests::event($method);
    $extension->started($event);
    $extension->ended($event);

    return $event->getTest()->getMetadata()->getSkip() ?? '';
}

it('hears each test start, end and outcome, each suite start, and the run\'s end', function (): void {
    expect(Extension::$events)->toBe([
        Events::SUITE_BEFORE => 'suite',
        Events::TEST_START => 'started',
        Events::TEST_SUCCESS => 'passed',
        Events::TEST_FAIL => 'failed',
        Events::TEST_ERROR => 'errored',
        Events::TEST_SKIPPED => 'neither',
        Events::TEST_INCOMPLETE => 'neither',
        Events::TEST_USELESS => 'neither',
        Events::TEST_END => 'ended',
        Events::RESULT_PRINT_AFTER => 'written',
    ]);
});

it('skips each test the run does not select as it starts, and every test of a suite that serves the application from another process', function (): void {
    $extension = selectingTheFirst(Scratch::directory());

    expect(skipOf($extension, 'testIsHeldByItsCase', 'Asserts'))->toBe('')
        ->and(skipOf($extension, 'testIsHeldByItsOwnPathToo', 'Asserts'))->toBe('The gate runs only the tests it selects.')
        ->and(skipOf($extension, 'testIsHeldByItsCase', 'PhpBrowser'))->toBe('The gate runs only the tests it selects.')
        ->and(skipOf($extension, 'testIsHeldByItsCase'))->toBe('');
});

it('records each selected test as it starts and by its outcome to the results file the gate names, and nothing of a test it skips', function (): void {
    $results = sprintf('%s/results.txt', Scratch::directory());
    putenv(sprintf('%s=%s', Variable::Results->value, $results));
    $extension = selectingTheFirst(Scratch::directory());
    $id = rawurlencode(CodeceptionTests::id('testIsHeldByItsCase'));

    foreach (['testIsHeldByItsOwnPathToo', 'testIsHeldByItsCase'] as $method) {
        $extension->started(CodeceptionTests::event($method));
        $extension->passed(CodeceptionTests::event($method));
        $extension->failed(CodeceptionTests::failed($method));
        $extension->errored(CodeceptionTests::failed($method));
        $extension->neither(CodeceptionTests::event($method));
        $extension->ended(CodeceptionTests::event($method));
    }

    $extension->passed(CodeceptionTests::event('testIsHeldByItsCase'));

    expect(file($results, FILE_IGNORE_NEW_LINES))->toBe([
        sprintf('started %s', $id),
        sprintf('passed %s', $id),
        sprintf('failed %s', $id),
        sprintf('errored %s', $id),
        sprintf('neither %s', $id),
    ]);
});

it('records nothing where the gate names no results file, or an empty one', function (): void {
    putenv(sprintf('%s=', Variable::Results->value));
    $root = Scratch::directory();
    $extension = selectingTheFirst($root);
    $extension->started(CodeceptionTests::event('testIsHeldByItsCase'));
    $extension->passed(CodeceptionTests::event('testIsHeldByItsCase'));

    expect(glob(sprintf('%s/*', $root)))->toBe([sprintf('%s/selected.txt', $root)]);
});

it('lists every test of an in-process suite and its groups once the run is done, running none, where it is told a file', function (): void {
    $listing = sprintf('%s/listing.json', Scratch::directory());
    $extension = new Extension([Option::Listing->value => $listing], []);
    $skips = [skipOf($extension, 'testIsHeldByItsCase'), skipOf($extension, 'testIsHeldByItsOwnPathToo', 'WebDriver')];
    $unwritten = is_file($listing);
    $extension->written();

    expect($skips)->toBe(['The gate runs only the tests it selects.', 'The gate runs only the tests it selects.'])
        ->and($unwritten)->toBeFalse()
        ->and(json_decode((string) file_get_contents($listing), associative: true))->toBe([
            'listed' => [CodeceptionTests::id('testIsHeldByItsCase')],
            'groups' => [
                'fast' => [CodeceptionTests::id('testIsHeldByItsCase')],
                'holds:src/Money.php' => [CodeceptionTests::id('testIsHeldByItsCase')],
            ],
        ]);
});

it('writes nothing once the run is done where it is told neither a listing nor a coverage map, nor a map with no vendor directory', function (): void {
    $root = Scratch::directory();
    selectingTheFirst($root)->written();
    selectingTheFirst($root, [Option::Coverage->value => sprintf('%s/coverage.php', $root)])->written();

    expect(glob(sprintf('%s/*', $root)))->toBe([sprintf('%s/selected.txt', $root)]);
});

it('measures each selected test with what it is handed and writes the map once the run is done, where it is told a file', function (): void {
    $root = (string) realpath(Scratch::directory());
    $map = sprintf('%s/coverage.php', $root);
    $source = sprintf('%s/src/Ran.php', $root);
    Scratch::write($root, 'src/Ran.php', "<?php\n\$ran = 1;\n");
    $measuring = new CodeCoverage(new CoverageDriverFake([[$source => [2 => 1]]]), new Filter());
    Scratch::write($root, 'selected.txt', sprintf("%s\n", CodeceptionTests::id('testIsHeldByItsCase')));
    $previous = getcwd();
    chdir($root);
    $extension = new Extension([Option::Selected->value => sprintf('%s/selected.txt', $root), Option::Coverage->value => $map, Option::Vendor->value => sprintf('%s/vendor', $root)], [], $measuring);
    require $source;
    $extension->started(CodeceptionTests::event('testIsHeldByItsCase'));
    $extension->ended(CodeceptionTests::event('testIsHeldByItsCase', 0.25));
    $extension->started(CodeceptionTests::event('testIsHeldByItsOwnPathToo'));
    $extension->ended(CodeceptionTests::event('testIsHeldByItsOwnPathToo', 0.5));
    chdir((string) $previous);
    $unwritten = is_file($map);
    $extension->written();
    $read = CoverageFile::read(Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::Codeception), $map);
    $test = TestId::of(CodeceptionTests::id('testIsHeldByItsCase'));

    expect($unwritten)->toBeFalse()
        ->and($read instanceof CoverageMap ? $read->testsCoveringFile(Path::of('src/Ran.php')) : $read)->toEqual(TestIds::of($test))
        ->and($read instanceof CoverageMap ? $read->durationOf($test) : $read)->toEqual(Seconds::of(0.25));
});

it('measures over the driver PHP has where it is handed nothing to measure with', function (): void {
    $root = Scratch::directory();
    $map = sprintf('%s/coverage.php', $root);
    selectingTheFirst($root, [Option::Coverage->value => $map, Option::Vendor->value => '/p/vendor'])->written();

    expect(is_file($map))->toBeTrue();
})->skip(fn(): bool => ! extension_loaded('pcov') && ! extension_loaded('xdebug'), 'only a PHP with a coverage driver');

it('notes in the guard file whether opcache on its command line could serve an original', function (): void {
    $guard = sprintf('%s/guard.txt', Scratch::directory());
    putenv(sprintf('%s=%s', Variable::Guard->value, $guard));
    new Extension([], []);

    expect(is_file($guard) ? file_get_contents($guard) : '')->toBe(Guard::noting(Opcache::ofCommandLine(ini_get('opcache.enable_cli'))));
});

it('notes in the guard file whether opcache could serve an original, after what it holds, only where a file is named', function (): void {
    $file = sprintf('%s/guard.txt', Scratch::directory());
    Extension::noting(file: false, opcache: Opcache::ofCommandLine('1'));
    Extension::noting('', Opcache::ofCommandLine('1'));
    $untouched = is_file($file);
    Extension::noting($file, Opcache::ofCommandLine('0'));
    Extension::noting($file, Opcache::ofCommandLine('1'));

    expect($untouched)->toBeFalse()
        ->and(file($file, FILE_IGNORE_NEW_LINES))->toBe(['uncached', 'cached']);
});
