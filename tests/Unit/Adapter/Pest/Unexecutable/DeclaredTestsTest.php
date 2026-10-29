<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\DeclaredTests;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Source;

it('finds the test files that declare a test of their own, never a helper or a file of code', function (): void {
    $declaring = Contents::of("<?php\nit('flags', fn () => true);\n");

    expect(DeclaredTests::among(
        Source::ofTest(Path::of('tests/MarkedSpec.php'), $declaring),
        Source::ofTest(Path::of('tests/Support/Marks.php'), Contents::of("<?php\nfinal class Marks {}\n")),
        Source::ofCode(Path::of('src/Pest.php'), $declaring),
    ))->toEqual(Paths::of(Path::of('tests/MarkedSpec.php')));
});
