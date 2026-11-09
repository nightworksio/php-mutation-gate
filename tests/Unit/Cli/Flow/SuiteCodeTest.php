<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\SuiteCode;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Php\PhpFile;

it('reads a file once however often it is asked for, and hands every file read over once', function (): void {
    $code = new SuiteCode();
    $first = $code->of(Path::of('tests/MoneyTest.php'), Contents::of("<?php\nfinal class MoneyTest {}\n"));
    $again = $code->of(Path::of('tests/MoneyTest.php'), Contents::of("<?php\nfinal class Other {}\n"));
    $taken = $code->taken();

    expect($again)->toBe($first)
        ->and($first)->toEqual(PhpFile::read(Contents::of("<?php\nfinal class MoneyTest {}\n")))
        ->and($taken)->toBe(['tests/MoneyTest.php' => $first])
        ->and($code->taken())->toBe([])
        ->and($code->of(Path::of('tests/MoneyTest.php'), Contents::of("<?php\nfinal class Other {}\n")))->not->toBe($first);
});
