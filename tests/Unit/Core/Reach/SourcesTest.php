<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Core\Reach\Sources;

it('holds nothing to begin with', function (): void {
    expect(Sources::none()->now(Path::of('src/Money.php')))->toEqual(Missing::at(Path::of('src/Money.php')))
        ->and(Sources::none()->before(Path::of('src/Money.php')))->toEqual(Missing::at(Path::of('src/Money.php')))
        ->and(Sources::none()->php())->toBe([]);
});

it('holds a file as it is on disk and as it was at the base, apart', function (): void {
    $sources = Sources::none()
        ->withNow(Path::of('src/Money.php'), Contents::of('now'))
        ->withBefore(Path::of('src/Money.php'), Contents::of('before'))
        ->withNow(Path::of('src/Money.php'), Contents::of('on disk'));

    expect($sources->now(Path::of('src/Money.php')))->toEqual(Contents::of('on disk'))
        ->and($sources->before(Path::of('src/Money.php')))->toEqual(Contents::of('before'))
        ->and($sources->before(Path::of('src/Clock.php')))->toEqual(Missing::at(Path::of('src/Clock.php')));
});

it('reads every PHP file on disk, and no other file', function (): void {
    $sources = Sources::none()
        ->withNow(Path::of('tests/Fakes/ClockFake.php'), Contents::of("<?php\n\nnamespace Tests\\Fakes;\n\nfinal class ClockFake {}\n"))
        ->withNow(Path::of('README.md'), Contents::of('# Money'))
        ->withNow(Path::of('src/Money.php'), Contents::of("<?php\n\nfinal class Money {}\n"))
        ->withBefore(Path::of('src/Old.php'), Contents::of("<?php\n\nfinal class Old {}\n"));

    $read = $sources->php();

    expect($read)->toHaveCount(2)
        ->and($read[0][0]->value())->toBe('tests/Fakes/ClockFake.php')
        ->and($read[1][0]->value())->toBe('src/Money.php')
        ->and($read[0][1])->toBeInstanceOf(PhpFile::class)
        ->and($read[0][1]->declares()->meet(Names::of('Tests\Fakes\ClockFake')))->toBeTrue()
        ->and($read[1][1]->declares()->meet(Names::of('Money')))->toBeTrue();
});

it('leaves the sources it came from as they were', function (): void {
    $sources = Sources::none();
    $sources->withNow(Path::of('src/Money.php'), Contents::of('now'));
    $sources->withBefore(Path::of('src/Money.php'), Contents::of('before'));

    expect($sources->now(Path::of('src/Money.php')))->toEqual(Missing::at(Path::of('src/Money.php')))
        ->and($sources->before(Path::of('src/Money.php')))->toEqual(Missing::at(Path::of('src/Money.php')));
});
