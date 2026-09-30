<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hold\HeldPath;
use NightWorksIO\MutationGate\Core\Hold\HoldsAttribute;
use NightWorksIO\MutationGate\Core\Hold\HoldsAttributes;
use NightWorksIO\MutationGate\Core\Hold\Standing;
use NightWorksIO\MutationGate\Core\Php\HoldsReader;
use NightWorksIO\MutationGate\Core\Php\Tokens;
use NightWorksIO\MutationGate\Core\Php\TopLevel;

$read = static function (string $code): HoldsAttributes {
    $tokens = array_values(array_filter(PhpToken::tokenize($code), static fn(PhpToken $token): bool => ! $token->isIgnorable()));

    return HoldsReader::in(Tokens::of($tokens), TopLevel::of($tokens)->scope());
};

it('reads a #[Holds] on a class and on its methods, with PHPUnit\'s group beside each or not', function () use ($read): void {
    $code = <<<'CODE'
        <?php

        namespace Tests;

        use NightWorksIO\MutationGate\Attribute\Holds;
        use PHPUnit\Framework\Attributes\Group;
        use PHPUnit\Framework\Attributes as Unit;
        use Other\Group as Grouping;

        #[Holds('src/Kernel.php'), Group('holds:src/Kernel.php')]
        final readonly class KernelTest extends TestCase
        {
            #[Holds('src/Boot.php')]
            #[Unit\Group('holds:src/Boot.php')]
            public function testBoots(): void {}

            #[Holds('src/Http'), Group('holds:src/Kernel.php'), Grouping('holds:src/Http')]
            protected static function testServes(): void {}

            #[Group(self::CLI), Holds('src/Cli')]
            private function testRuns(): void {}
        }

        #[Holds('src/Base.php')]
        abstract class BaseTest {}
        CODE;

    expect($read($code))->toEqual(HoldsAttributes::none()
        ->with(HoldsAttribute::onClass(HeldPath::literal('src/Kernel.php'), 10, 'Tests\KernelTest', grouped: true))
        ->with(HoldsAttribute::onMethod(HeldPath::literal('src/Boot.php'), 13, 'Tests\KernelTest::testBoots', grouped: true))
        ->with(HoldsAttribute::onMethod(HeldPath::literal('src/Http'), 17, 'Tests\KernelTest::testServes', grouped: false))
        ->with(HoldsAttribute::onMethod(HeldPath::literal('src/Cli'), 20, 'Tests\KernelTest::testRuns', grouped: false))
        ->with(HoldsAttribute::onClass(HeldPath::literal('src/Base.php'), 24, 'Tests\BaseTest', grouped: false)));
});

it('reads a method of a trait, an interface and an enum as held by it', function () use ($read): void {
    $code = <<<'PHP'
        <?php

        namespace Tests;

        use NightWorksIO\MutationGate\Attribute\Holds;

        trait Boots { #[Holds('src/Boot.php')] public function testBoots(): void {} }
        interface Serves { #[Holds('src/Http')] public function testServes(): void; }
        enum Suite: string implements Serves { #[Holds('src/Suite.php')] public function testServes(): void {} }
        PHP;

    expect($read($code))->toEqual(HoldsAttributes::none()
        ->with(HoldsAttribute::onMethod(HeldPath::literal('src/Boot.php'), 7, 'Tests\Boots::testBoots', grouped: false))
        ->with(HoldsAttribute::onMethod(HeldPath::literal('src/Http'), 8, 'Tests\Serves::testServes', grouped: false))
        ->with(HoldsAttribute::onMethod(HeldPath::literal('src/Suite.php'), 9, 'Tests\Suite::testServes', grouped: false)));
});

it('reads the attribute however its name is spelt, and no other attribute', function () use ($read): void {
    $code = <<<'PHP'
        <?php

        namespace NightWorksIO\MutationGate\Attribute\Tests;

        use NightWorksIO\MutationGate\Attribute;
        use NightWorksIO\MutationGate\Attribute\Holds as Held;
        use Other\Holds as Kept;

        it('a', #[Attribute\Holds('src/A.php')] fn () => true);
        it('b', #[\NightWorksIO\MutationGate\Attribute\Holds('src/B.php')] fn () => true);
        it('c', #[Held('src/C.php')] fn () => true);
        it('d', #[Holds('src/D.php'), Kept('src/D.php')] fn () => true);
        PHP;

    expect($read($code))->toEqual(HoldsAttributes::none()
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::literal('src/A.php'), 9))
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::literal('src/B.php'), 10))
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::literal('src/C.php'), 11)));
});

it('reads the attribute written relative to its namespace', function () use ($read): void {
    $code = <<<'PHP'
        <?php

        namespace NightWorksIO\MutationGate\Attribute;

        it('a', #[namespace\Holds('src/A.php')] fn () => true);
        PHP;

    expect($read($code))->toEqual(
        HoldsAttributes::none()->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::literal('src/A.php'), 5)),
    );
});

it('reads a #[Holds] on each closure by what the closure is passed to', function () use ($read): void {
    $code = <<<'PHP'
        <?php

        use NightWorksIO\MutationGate\Attribute\Holds;

        it('boots', #[Holds('src/Kernel.php')] function (): void {
            expect(true)->toBeTrue();
        });

        describe('the kernel', #[Holds('src/Kernel')] static function (): void {
            test('serves', #[Holds('src/Http')] static fn () => true);
        });

        beforeEach(#[Holds('src/Boot.php')] function &(): array { return []; });

        it('adds', fn () => true)->with([#[Holds('src/Rows.php')] fn () => 1]);

        $test = #[Holds('src/Kept.php')] fn () => true;

        array_map(#[Holds('src/Other.php')] fn ($row) => $row, []);
        PHP;

    expect($read($code))->toEqual(HoldsAttributes::none()
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::literal('src/Kernel.php'), 5))
        ->with(HoldsAttribute::at(Standing::DescribeClosure, HeldPath::literal('src/Kernel'), 9))
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::literal('src/Http'), 10))
        ->with(HoldsAttribute::at(Standing::HookClosure, HeldPath::literal('src/Boot.php'), 13))
        ->with(HoldsAttribute::at(Standing::DatasetClosure, HeldPath::literal('src/Rows.php'), 15))
        ->with(HoldsAttribute::at(Standing::KeptClosure, HeldPath::literal('src/Kept.php'), 17))
        ->with(HoldsAttribute::at(Standing::OtherClosure, HeldPath::literal('src/Other.php'), 19)));
});

it('reads a #[Holds] on a named function as held by the function', function () use ($read): void {
    $code = <<<'PHP'
        <?php

        namespace Tests;

        use NightWorksIO\MutationGate\Attribute\Holds;

        $kernel = Kernel::class;

        #[Holds('src/Kernel.php')]
        function &kernel(): array
        {
            #[Holds('src/Http')]
            function serve(): void {}

            return [];
        }
        PHP;

    expect($read($code))->toEqual(HoldsAttributes::none()
        ->with(HoldsAttribute::onFunction(HeldPath::literal('src/Kernel.php'), 9, 'Tests\kernel'))
        ->with(HoldsAttribute::onFunction(HeldPath::literal('src/Http'), 12, 'Tests\serve')));
});

it('reads a #[Holds] on anything else as standing elsewhere', function () use ($read): void {
    $code = <<<'PHP'
        <?php

        namespace Tests;

        use NightWorksIO\MutationGate\Attribute\Holds;

        function check(#[Holds('src/A.php')] $value): void {}

        final class Kernel
        {
            #[Holds('src/B.php')]
            private string $name = '';

            public function __construct(#[Holds('src/C.php')] public readonly int $port) {}
        }

        #[Holds('src/D.php')]
        trait Boots {}

        $kernel = new #[Holds('src/E.php')] class {
            #[Holds('src/F.php')]
            public function testIt(): void {}
        };
        PHP;

    expect($read($code))->toEqual(HoldsAttributes::none()
        ->with(HoldsAttribute::at(Standing::Elsewhere, HeldPath::literal('src/A.php'), 7))
        ->with(HoldsAttribute::at(Standing::Elsewhere, HeldPath::literal('src/B.php'), 11))
        ->with(HoldsAttribute::at(Standing::Elsewhere, HeldPath::literal('src/C.php'), 14))
        ->with(HoldsAttribute::at(Standing::Elsewhere, HeldPath::literal('src/D.php'), 17))
        ->with(HoldsAttribute::at(Standing::Elsewhere, HeldPath::literal('src/E.php'), 20))
        ->with(HoldsAttribute::at(Standing::Elsewhere, HeldPath::literal('src/F.php'), 21)));
});

it('reads a path as one string literal only when it is one, and anything else as it is written', function () use ($read): void {
    $code = <<<'PHP'
        <?php

        use NightWorksIO\MutationGate\Attribute\Holds;

        it('a', #[Holds(path: 'src/A.php')] fn () => true);
        it('b', #[Holds("src/B.php", 'src/Other.php')] fn () => true);
        it('c', #[Holds(self::KERNEL)] fn () => true);
        it('d', #[Holds(Paths::ROOT . '/Kernel.php')] fn () => true);
        it('e', #[Holds('src/' . 'E.php')] fn () => true);
        it('f', #[Holds] fn () => true);
        it('g', #[Holds()] fn () => true);
        it('h', #[Holds(path: self::KERNEL)] fn () => true);
        PHP;

    expect($read($code))->toEqual(HoldsAttributes::none()
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::literal('src/A.php'), 5))
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::literal('src/B.php'), 6))
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::expression('self::KERNEL'), 7))
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::expression("Paths::ROOT . '/Kernel.php'"), 8))
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::expression("'src/' . 'E.php'"), 9))
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::expression(''), 10))
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::expression(''), 11))
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::expression('self::KERNEL'), 12)));
});

it('reads each #[Holds] among attribute groups written together, on the line it is written on', function () use ($read): void {
    $code = <<<'PHP'
        <?php

        use NightWorksIO\MutationGate\Attribute\Holds;

        it('boots', #[
            Test,
            Holds('src/Kernel.php'),
        ] #[Other([1, 2]), Holds('src/Http')]
        #[Holds('src/Boot.php')] fn () => true);
        PHP;

    expect($read($code))->toEqual(HoldsAttributes::none()
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::literal('src/Kernel.php'), 7))
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::literal('src/Http'), 8))
        ->with(HoldsAttribute::at(Standing::TestClosure, HeldPath::literal('src/Boot.php'), 9)));
});

it('reads a file that ends inside an attribute or a declaration', function () use ($read): void {
    expect($read("<?php\n#[\\NightWorksIO\\MutationGate\\Attribute\\Holds('src/Kernel.php')"))->toEqual(
        HoldsAttributes::none()->with(HoldsAttribute::at(Standing::Elsewhere, HeldPath::literal('src/Kernel.php'), 2)),
    )
        ->and($read('<?php class'))->toEqual(HoldsAttributes::none())
        ->and($read("<?php\nclass Kernel {}"))->toEqual(HoldsAttributes::none());
});
