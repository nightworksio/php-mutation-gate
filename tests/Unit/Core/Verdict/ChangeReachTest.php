<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Core\Reach\FileRoles;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Verdict\ChangeReach;
use NightWorksIO\MutationGate\Tests\Support\Flows;

/** The files on disk: src/Equals.php declares Equals, and nothing but these files names anything. */
const REACH_EQUALS = "<?php\nnamespace App;\n\nfinal class Equals\n{\n    public static function of(): self\n    {\n        return new self();\n    }\n}\n";

/**
 * What a change to these files reaches over these files on disk, each
 * changed file read as it is now and as the change found it before.
 *
 * @param array<string, string> $files   what each file on disk holds, by its path
 * @param array<string, string> $before  what each changed PHP file held at the commit, by its path
 * @param list<Change>          $changes
 */
function reachOf(array $files, array $before, array $changes): ChangeReach
{
    $read = array_map(static fn(string $source): PhpFile => PhpFile::read(Contents::of($source)), $files);
    $then = ByPath::none();
    $now = ByPath::none();

    foreach ($changes as $change) {
        foreach ([$change->previousPath(), $change->path()] as $path) {
            $then = $then->with($path, array_key_exists($path->value(), $before) ? Contents::of($before[$path->value()]) : Missing::at($path));
            $now = $now->with($path, array_key_exists($path->value(), $files) ? Contents::of($files[$path->value()]) : Missing::at($path));
        }
    }

    return ChangeReach::of(Changes::of(...$changes), $then, $now, NamedFiles::read($read), reachRoles());
}

/**
 * What decides how the gate runs in the project: its tree src, the runner
 * defined in tests/Pest.php, and config/** by reach.everything; and
 * src/helpers.php, which its composer.json has loaded in every process.
 */
function reachRoles(): FileRoles
{
    return FileRoles::of(
        Layout::standard(Paths::of(Path::of('tests/Pest.php')))->decidedAlsoBy(Glob::of('config/**')),
        Packages::of(Flows::trees()),
        Paths::of(Path::of('src/helpers.php')),
    );
}

it('reaches the unit that names a changed class, however it names it', function (string $money): void {
    $files = ['src/Money.php' => $money, 'src/Equals.php' => REACH_EQUALS];

    expect(reachOf($files, $files, [Change::modified(Path::of('src/Equals.php'), Lines::none())])->reaches(Path::of('src/Money.php'), Paths::none()))
        ->toBeTrue();
})->with([
    'a trait it uses' => "<?php\nnamespace App;\n\nfinal class Money\n{\n    use Equals;\n}\n",
    'a static call' => "<?php\nnamespace App;\n\nfinal class Money\n{\n    public function same(): bool\n    {\n        return Equals::of() !== null;\n    }\n}\n",
    'new' => "<?php\nnamespace App;\n\nfinal class Money\n{\n    public function same(): object\n    {\n        return new Equals();\n    }\n}\n",
    'a type' => "<?php\nnamespace App;\n\nfinal class Money\n{\n    public function same(Equals \$equals): bool\n    {\n        return true;\n    }\n}\n",
    'its name in a string' => "<?php\nnamespace App;\n\nfinal class Money\n{\n    private const string SAME = 'App\\\\Equals';\n}\n",
    'its fully qualified name' => "<?php\nnamespace App;\n\nfinal class Money\n{\n    public function same(): object\n    {\n        return new \\App\\Equals();\n    }\n}\n",
]);

it('reaches a unit through what names a changed class, however far', function (): void {
    $files = [
        'src/Money.php' => "<?php\nnamespace App;\n\nfinal class Money\n{\n    public function rate(): Rate\n    {\n        return new Rate();\n    }\n}\n",
        'src/Rate.php' => "<?php\nnamespace App;\n\nfinal class Rate\n{\n    use Rounding;\n}\n",
        'src/Rounding.php' => "<?php\nnamespace App;\n\ntrait Rounding\n{\n    public function round(): Equals\n    {\n        return Equals::of();\n    }\n}\n",
        'src/Equals.php' => REACH_EQUALS,
        'src/Tax.php' => "<?php\nnamespace App;\n\nfinal class Tax\n{\n}\n",
    ];
    $reach = reachOf($files, $files, [Change::modified(Path::of('src/Equals.php'), Lines::none())]);

    expect($reach->reaches(Path::of('src/Money.php'), Paths::none()))->toBeTrue()
        ->and($reach->reaches(Path::of('src/Tax.php'), Paths::none()))->toBeFalse()
        ->and($reach->reaches(Path::of('src'), Paths::none()))->toBeTrue()
        ->and($reach->reaches(Path::root(), Paths::none()))->toBeTrue()
        ->and($reach->reaches(Path::of('sr'), Paths::none()))->toBeFalse()
        ->and(reachOf($files, $files, [])->reaches(Path::root(), Paths::none()))->toBeFalse();
});

it('reaches a kill through a test that killed it and names a changed class, where its unit does not', function (): void {
    $files = [
        'src/Money.php' => "<?php\nnamespace App;\n\nfinal class Money\n{\n}\n",
        'src/Equals.php' => REACH_EQUALS,
        'tests/MoneyTest.php' => "<?php\nuse App\\Equals;\nuse App\\Money;\n\nit('adds', fn () => expect(new Money())->toEqual(Equals::of()));\n",
        'tests/TaxTest.php' => "<?php\nit('taxes', fn () => expect(1)->toBe(1));\n",
    ];
    $reach = reachOf($files, $files, [Change::modified(Path::of('src/Equals.php'), Lines::none())]);

    expect($reach->reaches(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneyTest.php'))))->toBeTrue()
        ->and($reach->reaches(Path::of('src/Money.php'), Paths::of(Path::of('tests/TaxTest.php'))))->toBeFalse();
});

it('reaches no unit that names nothing a change declares', function (): void {
    $files = ['src/Money.php' => "<?php\nnamespace App;\n\nfinal class Money\n{\n    use Equals;\n}\n", 'src/Equals.php' => REACH_EQUALS, 'src/Tax.php' => "<?php\nnamespace App;\n\nfinal class Tax\n{\n}\n"];
    $reach = reachOf($files, $files, [Change::modified(Path::of('src/Tax.php'), Lines::none())]);

    expect($reach->reaches(Path::of('src/Money.php'), Paths::none()))->toBeFalse()
        ->and($reach->everything())->toEqual(Reasons::of())
        ->and($reach->reaches(Path::of('src/Tax.php'), Paths::none()))->toBeTrue();
});

it('reaches what named a class a change deleted, or renamed from under it', function (Change $change, string $renamedTo): void {
    $before = ['src/Equals.php' => REACH_EQUALS, 'src/Same.php' => str_replace('Equals', 'Same', REACH_EQUALS)];
    $money = ['src/Money.php' => "<?php\nnamespace App;\n\nfinal class Money\n{\n    use Equals;\n}\n"];
    $files = $renamedTo === '' ? $money : [...$money, $renamedTo => str_replace('Equals', 'Same', REACH_EQUALS)];

    expect(reachOf($files, $before, [$change])->reaches(Path::of('src/Money.php'), Paths::none()))->toBeTrue();
})->with([
    'deleted' => [Change::deleted(Path::of('src/Equals.php')), ''],
    'renamed, and changed' => [Change::renamed(Path::of('src/Equals.php'), Path::of('src/Same.php'), Lines::none()), 'src/Same.php'],
]);

it('reaches every kill with a change it cannot follow by name, and says why', function (string $changed, string $source, string $why): void {
    $files = $source === '' ? [] : [$changed => $source];
    $reach = reachOf(
        ['src/Money.php' => "<?php\nnamespace App;\n\nfinal class Money\n{\n}\n", ...$files],
        $files,
        [Change::modified(Path::of($changed), Lines::none())],
    );

    expect($reach->reaches(Path::of('src/Money.php'), Paths::none()))->toBeTrue()
        ->and($reach->everything())->toEqual(Reasons::of(Reason::that($why)));
})->with([
    'composer.json' => ['composer.json', '', '`composer.json` decides how the gate runs, so nothing judged before it stands.'],
    'composer.lock' => ['composer.lock', '', '`composer.lock` decides how the gate runs, so nothing judged before it stands.'],
    'the gate\'s config' => ['mutation-gate.yaml', '', '`mutation-gate.yaml` decides how the gate runs, so nothing judged before it stands.'],
    'the runner\'s definition' => ['tests/Pest.php', "<?php\n", '`tests/Pest.php` decides how the gate runs, so nothing judged before it stands.'],
    'a file reach.everything names' => ['config/services.yaml', '', '`config/services.yaml` decides how the gate runs, so nothing judged before it stands.'],
    'a file composer.json has loaded in every process' => [
        'src/helpers.php',
        "<?php\nfunction money(): int\n{\n    return 1;\n}\n",
        '`src/helpers.php` is loaded in every process by Composer\'s autoloader, so what it changes cannot be followed by name.',
    ],
]);

it('reaches only what names a changed file that is not PHP by a word of its name', function (): void {
    $files = [
        'src/Money.php' => "<?php\nnamespace App;\n\nfinal class Money\n{\n}\n",
        'tests/DocsTest.php' => "<?php\nit('documents', fn () => expect(file_get_contents(__DIR__ . '/../README.md'))->not->toBe(''));\n",
        'tests/RatesTest.php' => "<?php\nit('rates', fn () => expect(file_get_contents(__DIR__ . '/fixtures/rates.json'))->not->toBe(''));\n",
    ];
    $reach = reachOf(
        $files,
        $files,
        [
            Change::modified(Path::of('docs/guide.md'), Lines::none()),
            Change::modified(Path::of('README.md'), Lines::none()),
            Change::added(Path::of('logo.png'), Lines::none()),
            Change::modified(Path::of('resources/services.xml'), Lines::none()),
            Change::modified(Path::of('tests/fixtures/rates.json'), Lines::none()),
        ],
    );

    expect($reach->everything())->toEqual(Reasons::of())
        ->and($reach->reaches(Path::of('src/Money.php'), Paths::none()))->toBeFalse()
        ->and($reach->reaches(Path::of('src/Money.php'), Paths::of(Path::of('tests/DocsTest.php'))))->toBeTrue()
        ->and($reach->reaches(Path::of('src/Money.php'), Paths::of(Path::of('tests/RatesTest.php'))))->toBeTrue();
});

it('follows a change to a PHP file inside the tests by name, even one that runs code when it is loaded', function (): void {
    $files = [
        'src/Money.php' => "<?php\nnamespace App;\n\nfinal class Money\n{\n}\n",
        'tests/Support/Loads.php' => "<?php\nrequire __DIR__ . '/boot.php';\n",
        'tests/MoneyTest.php' => "<?php\nit('adds', fn () => expect(1)->toBe(1));\n",
    ];
    $reach = reachOf(
        $files,
        $files,
        [Change::modified(Path::of('tests/Support/Loads.php'), Lines::none())],
    );

    expect($reach->everything())->toEqual(Reasons::of())
        ->and($reach->reaches(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneyTest.php'))))->toBeFalse();
});

it('reaches no unit that names a class of the same name in another namespace', function (): void {
    $files = [
        'src/Money.php' => "<?php\nnamespace Billing;\n\nfinal class Money\n{\n    use Equals;\n}\n",
        'src/Billing/Equals.php' => "<?php\nnamespace Billing;\n\ntrait Equals\n{\n}\n",
        'src/Equals.php' => REACH_EQUALS,
    ];
    $reach = reachOf($files, $files, [Change::modified(Path::of('src/Equals.php'), Lines::none())]);

    expect($reach->reaches(Path::of('src/Money.php'), Paths::none()))->toBeFalse()
        ->and($reach->reaches(Path::of('src/Equals.php'), Paths::none()))->toBeTrue();
});

it('reaches no unit that calls a function a changed file only imports', function (): void {
    $files = [
        'src/Money.php' => "<?php\nnamespace App;\n\nuse function Other\\helper;\n\nfinal class Money\n{\n    public function add(): void\n    {\n        helper();\n    }\n}\n",
        'src/Tax.php' => "<?php\nnamespace Other;\n\nuse function Other\\helper;\n\nfinal class Tax\n{\n}\n",
    ];
    $reach = reachOf($files, $files, [Change::modified(Path::of('src/Tax.php'), Lines::none())]);

    expect($reach->reaches(Path::of('src/Money.php'), Paths::none()))->toBeFalse();
});

it('follows a test file a change deleted, or renamed, by name, though it ran code when it was loaded', function (Change $change): void {
    $before = ['tests/OldTest.php' => "<?php\nuse App\\Money;\n\nit('adds', fn () => expect(new Money())->not->toBeNull());\n"];
    $files = ['src/Money.php' => "<?php\nnamespace App;\n\nfinal class Money\n{\n}\n", 'tests/NewTest.php' => "<?php\nit('adds', fn () => expect(1)->toBe(1));\n"];
    $reach = reachOf($files, $before, [$change]);

    expect($reach->everything())->toEqual(Reasons::of())
        ->and($reach->reaches(Path::of('src/Money.php'), Paths::of(Path::of('tests/TaxTest.php'))))->toBeFalse()
        ->and($reach->reaches(Path::of('src/Money.php'), Paths::of(Path::of('tests/OldTest.php'))))->toBeTrue();
})->with([
    'deleted' => [Change::deleted(Path::of('tests/OldTest.php'))],
    'renamed' => [Change::renamed(Path::of('tests/OldTest.php'), Path::of('tests/NewTest.php'), Lines::none())],
]);

it('reaches what spells the name of a changed PHP file that runs code when it is loaded, and what declares, but nothing else', function (string $source): void {
    $files = [
        'src/Money.php' => "<?php\nnamespace App;\n\nfinal class Money\n{\n    public function rates(): array\n    {\n        return require __DIR__ . '/../resources/exchange-rates.php';\n    }\n}\n",
        'src/Kernel.php' => "<?php\nnamespace App;\n\nfinal class Kernel\n{\n    public function boot(): void\n    {\n        Booted::now();\n    }\n}\n",
        'src/Tax.php' => "<?php\nnamespace App;\n\nfinal class Tax\n{\n}\n",
        'resources/exchange-rates.php' => $source,
    ];
    $reach = reachOf($files, ['resources/exchange-rates.php' => "<?php\nreturn ['eur' => 1];\n"], [Change::modified(Path::of('resources/exchange-rates.php'), Lines::none())]);

    expect($reach->everything())->toEqual(Reasons::of())
        ->and($reach->reaches(Path::of('src/Money.php'), Paths::none()))->toBeTrue()
        ->and($reach->reaches(Path::of('src/Kernel.php'), Paths::none()))->toBe(str_contains($source, 'Booted'))
        ->and($reach->reaches(Path::of('src/Tax.php'), Paths::none()))->toBeFalse();
})->with([
    'returning a value' => ["<?php\nreturn ['eur' => 2];\n"],
    'only declaring now, though it ran code before' => ["<?php\nnamespace App;\n\nfinal class Ledger\n{\n}\n"],
    'declaring a class as it runs' => ["<?php\nnamespace App;\n\nfinal class Booted\n{\n    public static function now(): void\n    {\n    }\n}\n\nreturn ['eur' => 2];\n"],
]);

it('follows a PHP file that only declares by what it declares, not by the words of its name', function (): void {
    $files = [
        'src/Money.php' => "<?php\nnamespace App;\n\nfinal class Money\n{\n    private const string FILE = 'equals';\n}\n",
        'src/Equals.php' => REACH_EQUALS,
    ];
    $reach = reachOf($files, $files, [Change::modified(Path::of('src/Equals.php'), Lines::none())]);

    expect($reach->reaches(Path::of('src/Money.php'), Paths::none()))->toBeFalse();
});
