<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\SuiteCode;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\PhpFile;

/** @return ByPath<Contents> */
function suiteCodeFiles(string $held, string $other): ByPath
{
    $paths = Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/Pest.php'));

    return ByPath::mapping(
        $paths,
        static fn(Path $path): Contents => Contents::of($path->value() === 'tests/MoneyTest.php' ? $held : $other),
    );
}

/** @return array{list<string>, list<string>} */
function suiteCodeNames(string $code): array
{
    $read = PhpFile::read(Contents::of($code));

    return [NamedFiles::declaredIn($read), NamedFiles::mentionedIn($read)];
}

it('reads a file as reading it anew does', function (): void {
    $code = "<?php\nfinal class MoneyTest { public function adds(): void { new \\App\\Money(); } }\n";

    expect(SuiteCode::namesKept()->of(Path::of('tests/MoneyTest.php'), Contents::of($code)))
        ->toEqual(PhpFile::read(Contents::of($code)))
        ->and(SuiteCode::unkept()->of(Path::of('tests/MoneyTest.php'), Contents::of($code)))
        ->toEqual(PhpFile::read(Contents::of($code)));
});

it('hands the graph the names it kept of each file read once, and reads every other file', function (): void {
    $kept = "<?php\nfinal class MoneyTest { public function adds(): void { new \\App\\Money(); } }\n";
    $now = "<?php\nfinal class Other {}\n";
    $pest = "<?php\nuses(Tests\\TestCase::class);\n";
    $code = SuiteCode::namesKept();
    $code->of(Path::of('tests/MoneyTest.php'), Contents::of($kept));

    [$declares, $mentions] = $code->namesIn(suiteCodeFiles($now, $pest));
    [$declaresAgain] = $code->namesIn(suiteCodeFiles($now, $pest));

    expect([$declares, $mentions])->toBe([
        ['tests/MoneyTest.php' => suiteCodeNames($kept)[0], 'tests/Pest.php' => suiteCodeNames($pest)[0]],
        ['tests/MoneyTest.php' => suiteCodeNames($kept)[1], 'tests/Pest.php' => suiteCodeNames($pest)[1]],
    ])
        ->and($declaresAgain)->toBe(['tests/MoneyTest.php' => suiteCodeNames($now)[0], 'tests/Pest.php' => suiteCodeNames($pest)[0]]);
});

it('keeps nothing of a file read where no graph will take it', function (): void {
    $now = "<?php\nfinal class Other {}\n";
    $pest = "<?php\nuses(Tests\\TestCase::class);\n";
    $code = SuiteCode::unkept();
    $code->of(Path::of('tests/MoneyTest.php'), Contents::of("<?php\nfinal class MoneyTest {}\n"));

    expect($code->namesIn(suiteCodeFiles($now, $pest)))->toBe([
        ['tests/MoneyTest.php' => suiteCodeNames($now)[0], 'tests/Pest.php' => suiteCodeNames($pest)[0]],
        ['tests/MoneyTest.php' => suiteCodeNames($now)[1], 'tests/Pest.php' => suiteCodeNames($pest)[1]],
    ]);
});
