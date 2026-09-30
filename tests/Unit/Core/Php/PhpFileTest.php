<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Hold\Holding;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\PhpFile;

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
