<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Hold\HeldPath;
use NightWorksIO\MutationGate\Core\Hold\Holding;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use NightWorksIO\MutationGate\Core\Hold\HoldsAttribute;
use NightWorksIO\MutationGate\Core\Hold\HoldsAttributes;
use NightWorksIO\MutationGate\Core\Hold\Standing;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Tests\Support\Stopwatch;

$support = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Tests\Fakes;

    use App\Clock;

    final class ClockFake implements Clock
    {
        public function now(): \DateTimeImmutable
        {
            return new \DateTimeImmutable(namespace\Epoch::START);
        }
    }

    function aClock(): ClockFake
    {
        return new ClockFake();
    }
    PHP;

it('names every class and function it declares, fully qualified', function () use ($support): void {
    $file = PhpFile::read(Contents::of($support));

    expect($file->declares()->meet(Names::of('Tests\Fakes\ClockFake')))->toBeTrue()
        ->and($file->declares()->meet(Names::of('Tests\Fakes\aClock')))->toBeTrue()
        ->and($file->declares()->meet(Names::of('App\Clock', 'ClockFake', 'Tests\Fakes\Clock')))->toBeFalse();
});

it('mentions every name it writes, resolved, and nothing else', function () use ($support): void {
    $file = PhpFile::read(Contents::of($support));

    expect($file->mentions(Names::of('App\Clock')))->toBeTrue()
        ->and($file->mentions(Names::of('DateTimeImmutable')))->toBeTrue()
        ->and($file->mentions(Names::of('Tests\Fakes\Epoch')))->toBeTrue()
        ->and($file->mentions(Names::of('Tests\Fakes\ClockFake')))->toBeTrue()
        ->and($file->mentions(Names::of('App\Money', 'Tests\Fakes\Clock', 'Epoch')))->toBeFalse();
});

it('lists every name it mentions', function (): void {
    expect(PhpFile::read(Contents::of("<?php\nnamespace App;\nnew Money(new \\Clock());\nnew Money();\n"))->mentioned()->all())
        ->toBe(['app\\app', 'app', 'app\\money', 'money', 'clock']);
});

it('reads a file that mentions tens of thousands of names in linear time', function (): void {
    $source = sprintf("<?php\nnamespace App;\nfunction f() {\n%s}\n", implode('', array_map(static fn(int $at): string => sprintf("new C%d();\n", $at), range(1, 20_000))));
    $file = PhpFile::read(Contents::of(''));

    $seconds = Stopwatch::seconds(static function () use ($source, &$file): void {
        $file = PhpFile::read(Contents::of($source));
    });

    expect($file->mentioned()->all())->toHaveCount(40_004)
        ->and($seconds)->toBeLessThan(Stopwatch::BOUND);
});

it('only declares when loading it runs nothing, a closing tag at its end among it', function () use ($support): void {
    expect(PhpFile::read(Contents::of($support))->onlyDeclares())->toBeTrue()
        ->and(PhpFile::read(Contents::of("<?php\n\nclass Money {}\n?>\n"))->onlyDeclares())->toBeTrue()
        ->and(PhpFile::read(Contents::of("<?php\n\nit('ticks', fn () => true);\n"))->onlyDeclares())->toBeFalse();
});

it('reads the paths its #[Holds] declare held', function (): void {
    $test = <<<'PHP'
        <?php

        namespace Tests;

        use NightWorksIO\MutationGate\Attribute\Holds;

        #[Holds('src/Kernel.php')]
        final class KernelTest {}
        PHP;

    expect(PhpFile::read(Contents::of($test))->holdings())->toEqual(
        Holdings::none()->with(Holding::byAttribute('src/Kernel.php', 'Tests\KernelTest')),
    )
        ->and(PhpFile::read(Contents::of("<?php\n\nfinal class Money {}\n"))->holdings())->toEqual(Holdings::none());
});

it('reads every #[Holds] it writes, on closures as well as on classes and methods', function (): void {
    $test = <<<'PHP'
        <?php

        use NightWorksIO\MutationGate\Attribute\Holds;

        $test = #[Holds('src/Kernel.php')] fn () => true;

        it('boots', #[Holds('src/Boot.php')] #[Holds('src/Http')] fn () => true);
        PHP;

    expect(PhpFile::read(Contents::of($test))->holds())->toEqual(HoldsAttributes::none()
        ->with(HoldsAttribute::at(Standing::KeptClosure, HeldPath::literal('src/Kernel.php'), 5))
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::literal('src/Boot.php'), 7))
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::literal('src/Http'), 7)))
        ->and(PhpFile::read(Contents::of($test))->holdings())->toEqual(Holdings::none());
});
