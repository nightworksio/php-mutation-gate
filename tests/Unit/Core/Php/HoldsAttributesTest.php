<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hold\Holding;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use NightWorksIO\MutationGate\Core\Php\HoldsAttributes;
use NightWorksIO\MutationGate\Core\Php\TopLevel;

$held = static function (string $code): Holdings {
    $tokens = array_values(array_filter(PhpToken::tokenize($code), static fn(PhpToken $token): bool => ! $token->isIgnorable()));

    return HoldsAttributes::in(TopLevel::spelt(...$tokens), TopLevel::of($tokens)->scope());
};

it('reads a #[Holds] on a class as held by the class', function () use ($held): void {
    $code = <<<'PHP'
        <?php

        namespace Tests;

        use NightWorksIO\MutationGate\Attribute\Holds;

        #[Holds('src/Kernel.php')]
        final readonly class KernelTest extends TestCase
        {
            public function testBoots(): void {}
        }
        PHP;

    expect($held($code))->toEqual(Holdings::none()->with(Holding::byAttribute('src/Kernel.php', 'Tests\KernelTest')));
});

it('reads each #[Holds] on a method, however it is spelt, as held by that method', function () use ($held): void {
    $code = <<<'PHP'
        <?php

        namespace Tests;

        use NightWorksIO\MutationGate\Attribute as Gate;

        final class BootTest extends TestCase
        {
            #[Test]
            public static function other(): void {}

            #[Gate\Holds('src/Boot.php'), Test]
            #[\NightWorksIO\MutationGate\Attribute\Holds(path: "src/Http")]
            public function testBoots(): void {}
        }
        PHP;

    expect($held($code))->toEqual(Holdings::none()
        ->with(Holding::byAttribute('src/Boot.php', 'Tests\BootTest::testBoots'))
        ->with(Holding::byAttribute('src/Http', 'Tests\BootTest::testBoots')));
});

it('gives a method to the class declared last before it', function () use ($held): void {
    $code = <<<'PHP'
        <?php

        namespace Tests;

        use NightWorksIO\MutationGate\Attribute\Holds;

        final class FirstTest
        {
            public function make(): object
            {
                return new class {};
            }
        }

        #[Holds('src/Second.php')]
        final class SecondTest
        {
            #[Holds('src/Third.php')]
            public function testIt(): string
            {
                return FirstTest::class;
            }
        }
        PHP;

    expect($held($code))->toEqual(Holdings::none()
        ->with(Holding::byAttribute('src/Second.php', 'Tests\SecondTest'))
        ->with(Holding::byAttribute('src/Third.php', 'Tests\SecondTest::testIt')));
});

it('reads a path that is not a string literal as it is written', function () use ($held): void {
    $code = <<<'PHP'
        <?php

        use NightWorksIO\MutationGate\Attribute\Holds;

        #[Holds(self::KERNEL)]
        final class KernelTest {}
        PHP;

    expect($held($code))->toEqual(Holdings::none()->with(Holding::byAttribute('self :: KERNEL', 'KernelTest')));
});

it('reads no attribute that is not the package\'s own', function () use ($held): void {
    $code = <<<'PHP'
        <?php

        namespace Tests;

        use Other\Holds as Kept;

        #[Holds('src/Kernel.php'), Kept('src/Kernel.php')]
        final class KernelTest {}
        PHP;

    expect($held($code))->toEqual(Holdings::none());
});

it('reads a #[Holds] on a function outside any class as held by nothing it can name', function () use ($held): void {
    $code = <<<'PHP'
        <?php

        use NightWorksIO\MutationGate\Attribute\Holds;

        #[Holds('src/Kernel.php')]
        function helper(): void {}
        PHP;

    expect($held($code))->toEqual(Holdings::none()->with(Holding::byAttribute('src/Kernel.php', '::helper')));
});
