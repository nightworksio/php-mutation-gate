<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Php\Site;
use NightWorksIO\MutationGate\Tests\Support\Php;

it('is where a file reads a value: the file, the line, the token, and whether a test reads it', function (): void {
    $source = Php::source("<?php\n\$a = Money::RATE;", 'tests/MoneyTest.php', test: true);
    $site = Site::in($source, Php::indexOf($source, 'Money'));

    expect($site->file())->toEqual(Path::of('tests/MoneyTest.php'))
        ->and($site->line())->toEqual(Line::of(2))
        ->and($site->last())->toEqual(Line::of(2))
        ->and($site->token())->toBe(Php::indexOf($source, 'Money'))
        ->and($site->isInTest())->toBeTrue();
});

it('is run by the lines it is given, where a body runs a read that stands elsewhere', function (): void {
    $source = Php::source("<?php\nfunction f(\$a = Money::RATE) {\n    return \$a;\n}", 'src/F.php');
    $site = Site::runBy($source, Php::indexOf($source, 'Money'), Line::of(2), Line::of(4));

    expect([$site->line(), $site->last(), $site->token(), $site->isInTest()])
        ->toEqual([Line::of(2), Line::of(4), Php::indexOf($source, 'Money'), false]);
});
