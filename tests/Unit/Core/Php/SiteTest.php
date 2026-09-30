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
        ->and($site->token())->toBe(Php::indexOf($source, 'Money'))
        ->and($site->isInTest())->toBeTrue();
});
