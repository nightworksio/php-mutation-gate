<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\Additions;
use NightWorksIO\MutationGate\Core\Hold\HeldPath;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use NightWorksIO\MutationGate\Core\Hold\HoldsAttribute;
use NightWorksIO\MutationGate\Core\Hold\HoldsAttributes;
use NightWorksIO\MutationGate\Core\Hold\PestHolds;
use NightWorksIO\MutationGate\Core\Hold\Standing;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

$makeKernel = static fn(): HeldPath => HeldPath::literal('src/Kernel.php');

$held = static fn(HoldsAttribute ...$attributes): HoldsAttributes => array_reduce(
    $attributes,
    static fn(HoldsAttributes $all, HoldsAttribute $attribute): HoldsAttributes => $all->with($attribute),
    HoldsAttributes::none(),
);

$refusal = static function (string $file, HoldsAttribute ...$attributes) use ($held): string {
    $first = Paths::of(Path::of('tests/Pest.php'), Path::of('packages/billing/tests/Pest.php'));
    $read = PestHolds::after($first)->read(Path::of($file), $held(...$attributes));

    return $read instanceof CannotJudge ? $read->why() : 'read';
};

it('reads every #[Holds] from which a group follows, file by file', function () use ($makeKernel, $held): void {
    $kernel = $makeKernel();

    $read = PestHolds::none()
        ->read(Path::of('tests/Feature/KernelTest.php'), $held(
            HoldsAttribute::at(Standing::TestClosure, $kernel, 5),
            HoldsAttribute::at(Standing::DescribeClosure, HeldPath::literal('src/Http'), 9),
            HoldsAttribute::at(Standing::OtherClosure, HeldPath::literal('src/Boot.php'), 12),
            HoldsAttribute::at(Standing::Elsewhere, HeldPath::expression('self::CLI'), 15),
        ));
    $read = $read instanceof PestHolds
        ? $read->read(Path::of('tests/Unit/BootTest.php'), $held(
            HoldsAttribute::onClass($kernel, 10, 'Tests\BootTest', grouped: true),
            HoldsAttribute::onMethod(HeldPath::literal('src/Http'), 14, 'Tests\BootTest::testIt', grouped: true),
        ))
        : $read;

    $everyGroup = Groups::of(
        Group::named('holds:src/Kernel.php'),
        Group::named('holds:src/Http'),
        Group::named('holds:src/Boot.php'),
    );
    $missingOne = Groups::of(Group::named('holds:src/Kernel.php'), Group::named('holds:src/Http'));

    $missing = $read instanceof PestHolds ? $read->listedIn($missingOne) : $read;

    expect($read instanceof PestHolds ? $read->listedIn($everyGroup) : $read)->toEqual(Holdings::inGroups($everyGroup))
        ->and($missing instanceof CannotJudge ? $missing->why() : 'listed')->toStartWith(
            "tests/Feature/KernelTest.php:12: #[Holds('src/Boot.php')] is written here",
        )
        ->and(PestHolds::none()->read(Path::of('tests/Pest.php'), HoldsAttributes::none()))->toEqual(PestHolds::none());
});

it('refuses any #[Holds] in the file Pest loads before its plugins', function (string $file) use ($makeKernel, $refusal): void {
    $kernel = $makeKernel();

    expect($refusal($file, HoldsAttribute::at(Standing::TestClosure, $kernel, 7)))->toBe(sprintf(<<<'SAID'
        %1$s:7: #[Holds('src/Kernel.php')] stands in %1$s, which Pest loads before it starts any plugin, so its tests
        register before the filter that turns #[Holds] into a group exists.
        Hold the test with ->group('holds:src/Kernel.php') instead.
        SAID, $file));
})->with(['tests/Pest.php', 'packages/billing/tests/Pest.php']);

it('reads a file whose name only resembles the one Pest loads first', function (string $file) use ($makeKernel, $refusal): void {
    $kernel = $makeKernel();

    expect($refusal($file, HoldsAttribute::at(Standing::TestClosure, $kernel, 7)))->toBe('read');
})->with(['mytests/Pest.php', 'tests/Pest.php/KernelTest.php', 'tests/Unit/PestTest.php', 'other/tests/Pest.php']);

it('reads tests/Pest.php as any other file where the runner does not say Pest loads it first', function () use ($makeKernel, $held): void {
    $kernel = $makeKernel();

    $read = PestHolds::none()->read(Path::of('tests/Pest.php'), $held(HoldsAttribute::at(Standing::TestClosure, $kernel, 7)));

    expect($read)->toBeInstanceOf(PestHolds::class);
});

it('refuses a #[Holds] Pest never passes to its filter', function (HoldsAttribute $attribute, string $on) use ($refusal): void {
    expect($refusal('tests/Feature/KernelTest.php', $attribute))->toBe(sprintf(<<<'SAID'
        tests/Feature/KernelTest.php:4: %s stands on %s, which Pest never passes to its filter, so no group can follow from it.
        Hold the tests with ->group(%s) on a test, or on a describe around every test in the file.
        SAID, $attribute->written(), $on, $attribute->path()->group()));
})->with([
    'a hook' => [
        fn(): HoldsAttribute => HoldsAttribute::at(Standing::HookClosure, HeldPath::literal('src/Kernel.php'), 4),
        'a beforeEach, afterEach, beforeAll or afterAll closure',
    ],
    'a dataset' => [
        fn(): HoldsAttribute => HoldsAttribute::at(Standing::DatasetClosure, HeldPath::expression('self::KERNEL'), 4),
        'a dataset closure',
    ],
    'a named function' => [
        fn(): HoldsAttribute => HoldsAttribute::onFunction(HeldPath::literal('src/Kernel.php'), 4, 'Tests\kernel'),
        'a named function',
    ],
]);

it('refuses a #[Holds] on a closure kept in a variable', function () use ($makeKernel, $refusal): void {
    $kernel = $makeKernel();

    expect($refusal('tests/Feature/KernelTest.php', HoldsAttribute::at(Standing::KeptClosure, $kernel, 3)))->toBe(<<<'SAID'
        tests/Feature/KernelTest.php:3: #[Holds('src/Kernel.php')] stands on a closure kept in a variable, and tokens cannot tell which test that closure
        becomes, so the gate cannot check it against the groups Pest lists.
        Hold the test with ->group('holds:src/Kernel.php') where it is declared.
        SAID);
});

it('refuses a #[Holds] on a PHPUnit class or method whose path is not one string literal', function () use ($refusal): void {
    $path = HeldPath::expression('self::KERNEL');

    expect($refusal('tests/Unit/KernelTest.php', HoldsAttribute::onClass($path, 8, 'Tests\KernelTest', grouped: true)))
        ->toBe(<<<'SAID'
            tests/Unit/KernelTest.php:8: #[Holds(self::KERNEL)] stands on the PHPUnit class Tests\KernelTest, which Pest loads without its filter, and its path is not
            one string literal, so tokens cannot show that a #[Group] beside it holds the same path.
            Write the path as one string literal, with #[Group('holds:<path>')] beside it.
            SAID)
        ->and($refusal('tests/Unit/KernelTest.php', HoldsAttribute::onMethod($path, 11, 'Tests\KernelTest::testIt', grouped: false)))
        ->toBe(<<<'SAID'
            tests/Unit/KernelTest.php:11: #[Holds(self::KERNEL)] stands on the PHPUnit method Tests\KernelTest::testIt, which Pest loads without its filter, and its path is not
            one string literal, so tokens cannot show that a #[Group] beside it holds the same path.
            Write the path as one string literal, with #[Group('holds:<path>')] beside it.
            SAID);
});

it('refuses a #[Holds] on a PHPUnit class or method without its group, printing the line to add', function () use ($makeKernel, $refusal): void {
    $kernel = $makeKernel();

    expect($refusal('tests/Unit/KernelTest.php', HoldsAttribute::onClass($kernel, 8, 'Tests\KernelTest', grouped: false)))
        ->toBe(<<<'SAID'
            tests/Unit/KernelTest.php:8: #[Holds('src/Kernel.php')] stands on the PHPUnit class Tests\KernelTest, which Pest loads without its filter,
            so only PHPUnit's own #[Group] puts it in a group. Add this line beside it,
            with PHPUnit\Framework\Attributes\Group imported:
            #[Group('holds:src/Kernel.php')]
            SAID)
        ->and($refusal('tests/Unit/KernelTest.php', HoldsAttribute::onMethod($kernel, 11, 'Tests\KernelTest::testIt', grouped: false)))
        ->toBe(<<<'SAID'
            tests/Unit/KernelTest.php:11: #[Holds('src/Kernel.php')] stands on the PHPUnit method Tests\KernelTest::testIt, which Pest loads without its filter,
            so only PHPUnit's own #[Group] puts it in a group. Add this line beside it,
            with PHPUnit\Framework\Attributes\Group imported:
            #[Group('holds:src/Kernel.php')]
            SAID);
});

it('refuses the first #[Holds] from which no group follows', function () use ($makeKernel, $refusal): void {
    $kernel = $makeKernel();

    $why = $refusal(
        'tests/Feature/KernelTest.php',
        HoldsAttribute::at(Standing::TestClosure, $kernel, 3),
        HoldsAttribute::at(Standing::KeptClosure, $kernel, 6),
        HoldsAttribute::at(Standing::HookClosure, $kernel, 9),
    );

    expect($why)->toStartWith('tests/Feature/KernelTest.php:6: #[Holds(\'src/Kernel.php\')] stands on a closure kept in a variable');
});

it('answers the groups Pest lists once every literal path read is among them', function () use ($makeKernel, $held): void {
    $kernel = $makeKernel();

    $read = PestHolds::none()->read(Path::of('tests/Unit/KernelTest.php'), $held(
        HoldsAttribute::onClass($kernel, 8, 'Tests\KernelTest', grouped: true),
        HoldsAttribute::at(Standing::TestClosure, HeldPath::expression('self::HTTP'), 12),
    ));
    $listing = Groups::of(Group::named('holds:src/Kernel.php'), Group::named('holds:src/Http'), Group::named('slow'));
    $holdings = $read instanceof PestHolds ? $read->listedIn($listing) : $read;
    $trees = Trees::of(Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root())));
    $files = Fingerprints::of(
        Fingerprint::of(Path::of('src/Kernel.php'), Digest::of('a1')),
        Fingerprint::of(Path::of('src/Http/Controller.php'), Digest::of('b2')),
    );

    expect($holdings)->toEqual(Holdings::inGroups($listing))
        ->and($holdings instanceof Holdings ? $holdings->units($trees, $files, Additions::none()) : $holdings)->toEqual(Units::of(
            Unit::held(Path::of('src/Kernel.php'), Group::named('holds:src/Kernel.php')),
            Unit::held(Path::of('src/Http'), Group::named('holds:src/Http')),
        ))
        ->and(PestHolds::none()->listedIn(Groups::none()))->toEqual(Holdings::none());
});

it('says the plugin is not loaded when Pest lists no group for a literal path read', function () use ($makeKernel, $held): void {
    $kernel = $makeKernel();

    $read = PestHolds::none()->read(Path::of('tests/Feature/KernelTest.php'), $held(
        HoldsAttribute::at(Standing::TestClosure, $kernel, 5),
    ));
    $read = $read instanceof PestHolds
        ? $read->read(Path::of('tests/Feature/HttpTest.php'), $held(
            HoldsAttribute::at(Standing::DescribeClosure, HeldPath::literal('src/Http'), 9),
        ))
        : $read;
    $answer = $read instanceof PestHolds ? $read->listedIn(Groups::of(Group::named('holds:src/Kernel.php'))) : $read;

    expect($answer)->toEqual(CannotJudge::because(<<<'SAID'
        tests/Feature/HttpTest.php:9: #[Holds('src/Http')] is written here, but Pest lists no group holds:src/Http.
        The package's Pest plugin turns each #[Holds] into its group, so it is not loaded.
        Pest loads the plugins vendor/pest-plugins.json lists, which the Composer plugin
        pestphp/pest-plugin writes from each package's extra.pest.plugins as Composer dumps
        its autoloader: allow that Composer plugin and run composer dump-autoload.
        SAID));
});
