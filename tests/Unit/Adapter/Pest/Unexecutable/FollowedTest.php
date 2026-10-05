<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Followed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Php\Codebase;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Php\Source;
use NightWorksIO\MutationGate\Core\Php\Symbol;

$codebase = static fn(): Codebase => Codebase::of(
    Source::read(Path::of('src/Money.php'), Contents::of("<?php\nnamespace App;\nfinal class Money { const RATE = 2; }\n"), test: false),
    Source::read(Path::of('tests/MoneyTest.php'), Contents::of("<?php\nuse App\\Money;\n\$rate = Money::RATE;\n"), test: true),
);

it('answers where the codebase reads a value, as the codebase does, and nothing it can follow for an unnamed one', function () use ($codebase): void {
    $rate = Symbol::constant('App\Money', 'RATE');

    expect(new Followed($codebase())->references($rate))->toEqual($codebase()->references($rate))
        ->and(new Followed($codebase())->references(Symbol::constant(Nameless::code(), 'RATE')))
        ->toEqual($codebase()->references(Symbol::constant(Nameless::code(), 'RATE')));
});

it('follows each symbol once, and answers it again as it found it the first time', function () use ($codebase): void {
    $followed = new Followed($codebase());

    expect($followed->references(Symbol::constant('App\Money', 'RATE')))
        ->toBe($followed->references(Symbol::constant('App\Money', 'RATE')))
        ->and($followed->references(Symbol::constant('App\Money', 'RATE')))
        ->not->toBe($followed->references(Symbol::constant('App\Money', 'OTHER')));
});
