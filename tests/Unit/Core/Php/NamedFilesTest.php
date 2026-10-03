<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Tests\Support\Growth;

/**
 * These files, each read as PHP.
 *
 * @param  array<string, string> $sources what each file holds, by its path
 * @return array<string, PhpFile>
 */
function namedFilesRead(array $sources): array
{
    return array_map(static fn(string $source): PhpFile => PhpFile::read(Contents::of($source)), $sources);
}

// a names b and c, b names d and a, c names d; e names a, and nothing names e.
$cycle = NamedFiles::byName(
    ['a.php' => ['a'], 'b.php' => ['b'], 'c.php' => ['c'], 'd.php' => ['d'], 'e.php' => ['e']],
    ['a.php' => ['b', 'c', 'b'], 'b.php' => ['d', 'a'], 'c.php' => ['d'], 'd.php' => [], 'e.php' => ['a', 'unknown']],
);

it('reaches every file the files name, in turn, each once, in the order reached, past a cycle', function () use ($cycle): void {
    expect($cycle->reachedFrom('a.php'))->toBe(['a.php', 'b.php', 'c.php', 'd.php'])
        ->and($cycle->reachedFrom('d.php', 'c.php', 'd.php'))->toBe(['d.php', 'c.php'])
        ->and($cycle->reachedFrom('unnamed.php'))->toBe(['unnamed.php'])
        ->and($cycle->reachedFrom())->toBe([]);
});

it('finds every file that mentions a name, and every file that names those, in turn, each once, in the order reached', function () use ($cycle): void {
    expect($cycle->naming('d'))->toBe(['b.php', 'c.php', 'a.php', 'e.php'])
        ->and($cycle->naming('a', 'unknown'))->toBe(['b.php', 'e.php', 'a.php'])
        ->and($cycle->naming('e'))->toBe([])
        ->and($cycle->naming())->toBe([]);
});

it('links a file only to those that declare what it mentions', function (): void {
    $named = NamedFiles::byName(['support.php' => ['helper']], ['test.php' => ['helper', 'other'], 'other.php' => ['test']]);

    expect($named->reachedFrom('test.php'))->toBe(['test.php', 'support.php'])
        ->and($named->reachedFrom('other.php'))->toBe(['other.php']);
});

it('links PHP files by the names they resolve, a file to itself by what it declares, and tells apart a name in one namespace from the same in another', function (string $user, bool $links): void {
    $named = NamedFiles::read(namedFilesRead([
        'src/Equals.php' => "<?php\nnamespace App;\n\nfinal class Equals\n{\n}\n",
        'src/Money.php' => $user,
    ]));

    expect($named->reachedFrom('src/Money.php'))->toBe($links ? ['src/Money.php', 'src/Equals.php'] : ['src/Money.php'])
        ->and($named->naming(...NamedFiles::declaredIn(PhpFile::read(Contents::of("<?php\nnamespace App;\n\nfinal class Equals\n{\n}\n")))))
        ->toBe($links ? ['src/Equals.php', 'src/Money.php'] : ['src/Equals.php']);
})->with([
    'unqualified, in its namespace' => ["<?php\nnamespace App;\n\nfinal class Money\n{\n    use Equals;\n}\n", true],
    'imported' => ["<?php\nnamespace Billing;\n\nuse App\\Equals;\n\nfinal class Money\n{\n    public function same(Equals \$other): bool\n    {\n    }\n}\n", true],
    'fully qualified' => ["<?php\nnamespace Billing;\n\nfinal class Money\n{\n    public function same(): object\n    {\n        return new \\App\\Equals();\n    }\n}\n", true],
    'its full name in a string' => ["<?php\nnamespace Billing;\n\nfinal class Money\n{\n    private const string SAME = 'App\\\\Equals';\n}\n", true],
    'a class of the same name in another namespace' => ["<?php\nnamespace Billing;\n\nfinal class Money\n{\n    use Equals;\n}\n", false],
    'the word in a string' => ["<?php\nnamespace App;\n\nfinal class Money\n{\n    private const string SAME = 'equals';\n}\n", false],
]);

it('links a file to one that declares a constant it uses, by its last segment, and not by a word in a string', function (): void {
    $named = NamedFiles::read(namedFilesRead([
        'src/limits.php' => "<?php\nnamespace App;\n\nconst LIMIT = 3;\ndefine('App\\\\CEILING', 4);\n",
        'src/Money.php' => "<?php\nnamespace App;\n\nfinal class Money\n{\n    private const int MAX = LIMIT + \\App\\CEILING;\n}\n",
        'src/Tax.php' => "<?php\nnamespace App;\n\nfinal class Tax\n{\n    private const string NAME = 'limit';\n}\n",
    ]));

    expect($named->reachedFrom('src/Money.php'))->toBe(['src/Money.php', 'src/limits.php'])
        ->and(NamedFiles::declaredIn(PhpFile::read(Contents::of("<?php\nnamespace App;\n\nconst LIMIT = 3;\nfinal class Limit\n{\n}\n"))))
        ->toBe(['app\limit', 'const limit'])
        ->and($named->naming('const ceiling'))->toBe(['src/Money.php']);
});

it('reads an imported function or constant as a name a file uses, not one it declares', function (): void {
    $file = PhpFile::read(Contents::of("<?php\nnamespace App;\n\nuse function Other\\helper;\nuse const Other\\LIMIT;\n\nfinal class Money\n{\n}\n"));
    $named = NamedFiles::read(namedFilesRead([
        'src/helpers.php' => "<?php\nnamespace Other;\n\nfunction helper(): void\n{\n}\n",
        'src/Money.php' => "<?php\nnamespace App;\n\nuse function Other\\helper;\n\nfinal class Money\n{\n    public function add(): void\n    {\n        helper();\n    }\n}\n",
    ]));

    expect(NamedFiles::declaredIn($file))->toBe(['app\money'])
        ->and($named->reachedFrom('src/Money.php'))->toBe(['src/Money.php', 'src/helpers.php']);
});

it('names a file that is not PHP by each word of its name, which a file names by spelling it in a string', function (): void {
    $named = NamedFiles::read(namedFilesRead([
        'tests/RatesTest.php' => "<?php\nit('rates', fn () => expect(file_get_contents(__DIR__ . '/fixtures/exchange-rates.json'))->not->toBe(''));\n",
        'tests/HeredocTest.php' => "<?php\n\$path = <<<TXT\nfixtures/{\$dir}/exchange.json\nTXT;\n",
        'tests/MoneyTest.php' => "<?php\nit('adds', fn () => expect(1)->toBe(1));\n",
    ]));

    expect(NamedFiles::nameOf(Path::of('tests/fixtures/exchange-rates.json')))->toBe(['file exchangerates', 'file exchange', 'file rates'])
        ->and(NamedFiles::nameOf(Path::of('README')))->toBe(['file readme'])
        ->and($named->naming(...NamedFiles::nameOf(Path::of('tests/fixtures/rates.yaml'))))->toBe(['tests/RatesTest.php'])
        ->and($named->naming(...NamedFiles::nameOf(Path::of('tests/fixtures/exchange.json'))))->toBe(['tests/RatesTest.php', 'tests/HeredocTest.php'])
        ->and($named->naming('rates'))->toBe([]);
});

it('walks names back linear in the files and the names they hold', function (): void {
    $chain = static function (int $size): Closure {
        $declares = [];
        $mentions = [];

        for ($at = 0; $at < $size; $at++) {
            $declares[sprintf('src/C%d.php', $at)] = [sprintf('c%d', $at)];
            $mentions[sprintf('src/C%d.php', $at)] = [sprintf('c%d', $at + 1)];
        }

        $named = NamedFiles::byName($declares, $mentions);

        return static fn(): array => $named->naming(sprintf('c%d', $size));
    };

    expect($chain(3)())->toBe(['src/C2.php', 'src/C1.php', 'src/C0.php'])
        ->and(Growth::of(250, $chain))->toBeLessThan(Growth::LINEAR);
});
