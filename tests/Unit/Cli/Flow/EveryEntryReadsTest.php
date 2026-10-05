<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\EveryEntryReads;
use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Cli\Flow\Keying;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The flows' files, with support that runs when loaded, support that only declares, and the runner's own file. */
const EVERY_ENTRY_FILES = [
    ...Flows::FILES,
    'tests/Support/Boot.php' => "<?php\n\ndate_default_timezone_set('UTC');\n",
    'tests/Support/Clock.php' => "<?php\n\nnamespace Tests\\Support;\n\nfinal class Clock {}\n",
    'tests/Pest.php' => "<?php\n\nnamespace Tests;\n\nfunction clock(): void {}\n",
];

/**
 * What every entry of a project of these files reads, with this config file.
 *
 * @param array<string, string> $files
 */
function everyEntryReads(Path|Absent $config, array $files = EVERY_ENTRY_FILES): EveryEntryReads
{
    $project = Scratch::directory();

    foreach ($files as $path => $contents) {
        Scratch::write($project, $path, $contents);
    }

    $checkout = new ChangeSourceFake(Revision::head(), Changes::none(), [Revision::workingTree()->name() => $files]);
    $adapters = Flows::adapters($project, [], $checkout);
    $inventory = Inventory::of($adapters, Flows::settings());

    return EveryEntryReads::of(
        $inventory instanceof Inventory ? $inventory : throw new LogicException($inventory->why()),
        Keying::exceptions($adapters, Flows::settings(), Flows::setup()),
        $config,
    );
}

it('says whether every entry reads a file, by its place and, under the test directories, its role', function (
    string $path,
    bool $read,
): void {
    expect(everyEntryReads(Path::of('mutation-gate.json'))->isReadByEvery(Path::of($path)))->toBe($read);
})->with([
    'the config' => ['mutation-gate.json', true],
    'a file outside that is no PHP source' => ['composer.json', true],
    'a CI definition' => ['.github/workflows/mutation.yml', true],
    'a file that defines the runner, outside the test directories' => ['phpunit.xml', true],
    'a file that defines the runner, under them' => ['tests/Pest.php', true],
    'support that runs when loaded' => ['tests/Support/Boot.php', true],
    'a PHP source' => ['src/Money.php', false],
    'a file of test cases' => ['tests/MoneyTest.php', false],
    'support that only declares' => ['tests/Support/Clock.php', false],
    'the baseline, which no key reads' => ['mutation-gate.baseline.json', false],
]);

it('reads a file that defined the runner under the test directories though it is gone from them', function (): void {
    $gone = array_diff_key(EVERY_ENTRY_FILES, ['tests/Pest.php' => '']);

    expect(everyEntryReads(Absent::setting(), $gone)->isReadByEvery(Path::of('tests/Pest.php')))->toBeTrue();
});

it('reads a PHP config only where it is the config', function (): void {
    expect(everyEntryReads(Path::of('mutation-gate.php'))->isReadByEvery(Path::of('mutation-gate.php')))->toBeTrue()
        ->and(everyEntryReads(Absent::setting())->isReadByEvery(Path::of('mutation-gate.php')))->toBeFalse();
});

it('names the first file of a change that every entry reads, by either path, and none where none is', function (
    Changes $changes,
    string|NotGiven $first,
): void {
    $named = everyEntryReads(Absent::setting())->firstIn($changes);

    expect($named instanceof Path ? $named->value() : $named)->toEqual($first);
})->with([
    'a source, then a file every entry reads' => [
        fn(): Changes => Changes::of(
            Change::modified(Path::of('src/Money.php'), Lines::none()),
            Change::modified(Path::of('composer.json'), Lines::none()),
            Change::modified(Path::of('phpunit.xml'), Lines::none()),
        ),
        'composer.json',
    ],
    'a file every entry read, renamed to a source' => [
        fn(): Changes => Changes::of(Change::renamed(Path::of('tests/Support/Boot.php'), Path::of('src/Boot.php'), Lines::none())),
        'tests/Support/Boot.php',
    ],
    'sources and test cases alone' => [
        fn(): Changes => Changes::of(
            Change::modified(Path::of('src/Money.php'), Lines::none()),
            Change::deleted(Path::of('tests/MoneyTest.php')),
        ),
        NotGiven::value(),
    ],
]);
