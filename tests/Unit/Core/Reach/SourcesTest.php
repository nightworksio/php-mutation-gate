<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Core\Reach\Sources;

it('holds nothing to begin with', function (): void {
    expect(Sources::none()->now(Path::of('src/Money.php')))->toEqual(Missing::at(Path::of('src/Money.php')))
        ->and(Sources::none()->before(Path::of('src/Money.php')))->toEqual(Missing::at(Path::of('src/Money.php')))
        ->and(Sources::none()->php())->toHaveCount(0);
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
    $clock = $read->at(Path::of('tests/Fakes/ClockFake.php'), PhpFile::read(Contents::of('')));
    $money = $read->at(Path::of('src/Money.php'), PhpFile::read(Contents::of('')));

    expect($read->paths())->toEqual(Paths::of(Path::of('tests/Fakes/ClockFake.php'), Path::of('src/Money.php')))
        ->and($clock->declares()->meet(Names::of('Tests\Fakes\ClockFake')))->toBeTrue()
        ->and($money->declares()->meet(Names::of('Money')))->toBeTrue();
});

it('holds the files a change source read together, a missing one among them', function (): void {
    $paths = Paths::of(Path::of('src/Money.php'), Path::of('src/Gone.php'));
    $sources = Sources::of(
        ByPath::mapping($paths, static fn(Path $path): Contents|Missing => $path->value() === 'src/Money.php' ? Contents::of("<?php\n\nfinal class Money {}\n") : Missing::at($path)),
        ByPath::mapping($paths, static fn(Path $path): Contents => Contents::of(sprintf('before %s', $path->value()))),
    );

    expect($sources->now(Path::of('src/Gone.php')))->toEqual(Missing::at(Path::of('src/Gone.php')))
        ->and($sources->before(Path::of('src/Gone.php')))->toEqual(Contents::of('before src/Gone.php'))
        ->and($sources->php()->paths())->toEqual(Paths::of(Path::of('src/Money.php')));
});

it('leaves the sources it came from as they were', function (): void {
    $sources = Sources::none();
    $sources->withNow(Path::of('src/Money.php'), Contents::of('now'));
    $sources->withBefore(Path::of('src/Money.php'), Contents::of('before'));

    expect($sources->now(Path::of('src/Money.php')))->toEqual(Missing::at(Path::of('src/Money.php')))
        ->and($sources->before(Path::of('src/Money.php')))->toEqual(Missing::at(Path::of('src/Money.php')));
});
