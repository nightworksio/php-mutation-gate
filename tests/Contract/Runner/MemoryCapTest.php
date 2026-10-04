<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The memory_limit every PHP process of the fixture's tests ran under while this ran, one line each.
 *
 * @return list<string>
 */
$probed = static function (Closure $run): array {
    $file = sprintf('%s/memory', Scratch::directory());

    // Symfony's Process passes on only the variables PHP also has in $_SERVER.
    putenv(sprintf('LIBRARY_MEMORY=%s', $file));
    $_SERVER['LIBRARY_MEMORY'] = $file;

    try {
        $run();
    } finally {
        putenv('LIBRARY_MEMORY');
        unset($_SERVER['LIBRARY_MEMORY']);
    }

    $text = is_file($file) ? (string) file_get_contents($file) : '';

    return $text === '' ? [] : explode("\n", rtrim($text, "\n"));
};

/**
 * The probed lines of mutants' own processes.
 *
 * @param list<string> $lines
 * @return list<string>
 */
function inMutants(array $lines): array
{
    return array_values(array_filter($lines, static fn(string $line): bool => str_starts_with($line, 'mutant ')));
}

$capped = static function (Library $library) use ($probed): void {
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds')))
        ->cappedAt(MemoryCap::of(64, MemoryUnit::Megabytes));
    $result = null;
    $mutated = $probed(static function () use ($library, $request, &$result): void {
        $result = $library->runner()->mutate($request);
    });
    $mutants = $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [];
    $reproduced = $mutants === [] ? [] : $probed(static function () use ($library, $request, $mutants): void {
        $library->runner()->reproduce(Reproducible::of($mutants[0]), $request, Seconds::of(60.0));
    });

    expect($mutants)->not->toBeEmpty()
        ->and($mutants[0] ?? null)->toBeInstanceOf(Mutant::class)
        ->and(inMutants($mutated))->not->toBeEmpty()
        ->and(array_unique(inMutants($mutated)))->toBe(['mutant 64M'])
        ->and(inMutants($reproduced))->not->toBeEmpty()
        ->and(array_unique(inMutants($reproduced)))->toBe(['mutant 64M']);
};

it('runs every mutant\'s process of a Pest run, and of a reproduction, under the cap', function () use ($capped): void {
    $capped(Library::pest(Patching::off()));
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('runs every mutant\'s process of an Infection run, and of a reproduction, under the cap', function () use (
    $capped,
): void {
    $capped(Library::infection(Seconds::of(10.0)));
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs its library');

it('runs every mutant\'s process of a phpunit run, and of a reproduction, under the cap', function () use ($capped): void {
    $capped(Library::phpunit());
})->skip(! Library::isPhpUnitInstalled(), 'the PHPUnit runner contracts steps install its library');

/**
 * The status and limit of each mutant of Money::add a run under a 64M cap
 * gives, while each mutant's own process holds memory until PHP stops it, as
 * LIBRARY_HOG says, with one more variable set where it names one.
 *
 * @return list<string>
 */
$hogged = static function (Library $library, string $hog, string $also = '', string $alsoValue = ''): array {
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds')))
        ->cappedAt(MemoryCap::of(64, MemoryUnit::Megabytes));
    $set = ['LIBRARY_HOG' => $hog, ...($also === '' ? [] : [$also => $alsoValue])];

    // Symfony's Process passes on only the variables PHP also has in $_SERVER.
    foreach ($set as $name => $value) {
        putenv(sprintf('%s=%s', $name, $value));
        $_SERVER[$name] = $value;
    }

    try {
        $result = $library->runner()->mutate($request);
    } finally {
        foreach (array_keys($set) as $name) {
            putenv($name);
            unset($_SERVER[$name]);
        }
    }

    return $result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): string => sprintf(
            '%s %s',
            $mutant->status()->value,
            $mutant->limit() instanceof MemoryCap ? $mutant->limit()->written() : 'unlimited',
        ),
        iterator_to_array($result->mutants(), preserve_keys: false),
    ) : [$result->why()];
};

it('reads a Pest mutant whose own process ran out of the cap as out of memory, and of its own limit as killed', function () use (
    $hogged,
): void {
    $library = Library::pest(Patching::off());

    expect(array_unique($hogged($library, 'cap')))->toBe(['out-of-memory 64M'])
        ->and(array_unique($hogged($library, '48M')))->toBe(['killed unlimited']);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('reads an Infection mutant whose own process ran out of the cap as out of memory, and of its own limit as it was', function () use (
    $hogged,
): void {
    $library = Library::infection(Seconds::of(10.0));

    expect(array_unique($hogged($library, 'cap')))->toBe(['out-of-memory 64M'])
        ->and(array_unique($hogged($library, '48M')))->not->toContain('out-of-memory 64M');
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs its library');

it('reads an Infection mutant out of the cap as out of memory where PHP\'s own ini shows errors nowhere', function () use (
    $hogged,
): void {
    $production = sprintf('%s/production', Scratch::directory());
    mkdir($production);
    file_put_contents(sprintf('%s/errors.ini', $production), "display_errors=0\n");

    expect(array_unique($hogged(
        Library::infection(Seconds::of(10.0)),
        'cap',
        MemoryCap::SCAN_DIR,
        sprintf('%s%s', PATH_SEPARATOR, $production),
    )))->toBe(['out-of-memory 64M']);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs its library');

it('reads an Infection mutant as out of memory with no limit, and so too heavy to judge, where its PHPUnit config hides errors again', function () use (
    $hogged,
): void {
    $copy = Scratch::copy(Library::INFECTION_DIRECTORY);
    $config = sprintf('%s/phpunit.xml', $copy);
    $hiding = '<php><ini name="display_errors" value="0"/></php></phpunit>';
    file_put_contents($config, str_replace('</phpunit>', $hiding, (string) file_get_contents($config)));

    expect(array_unique($hogged(Library::infectionAt($copy, Seconds::of(10.0)), 'cap')))
        ->toBe(['out-of-memory unlimited']);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs its library');

it('reads a phpunit mutant whose own process ran out of the cap as out of memory, and of its own limit as killed', function () use (
    $hogged,
): void {
    $library = Library::phpunit();

    expect(array_unique($hogged($library, 'cap')))->toBe(['out-of-memory 64M'])
        ->and(array_unique($hogged($library, '48M')))->toBe(['killed unlimited']);
})->skip(! Library::isPhpUnitInstalled(), 'the PHPUnit runner contracts steps install its library');

it('reads a phpunit mutant out of the cap as out of memory where PHP\'s own ini shows errors nowhere', function () use (
    $hogged,
): void {
    $production = sprintf('%s/production', Scratch::directory());
    mkdir($production);
    file_put_contents(sprintf('%s/errors.ini', $production), "display_errors=0\n");

    expect(array_unique($hogged(Library::phpunit(), 'cap', MemoryCap::SCAN_DIR, sprintf('%s%s', PATH_SEPARATOR, $production))))
        ->toBe(['out-of-memory 64M']);
})->skip(! Library::isPhpUnitInstalled(), 'the PHPUnit runner contracts steps install its library');
