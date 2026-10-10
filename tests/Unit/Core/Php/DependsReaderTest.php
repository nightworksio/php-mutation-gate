<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\DependsReader;
use NightWorksIO\MutationGate\Core\Test\TestDependencies;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * The ids a test depends on, in turn, as one file records them.
 *
 * @return list<string>
 */
function dependedOn(string $code, string $test): array
{
    $read = DependsReader::in(Contents::of($code));

    return array_map(
        static fn(TestId $id): string => $id->value(),
        [...TestDependencies::closure(TestIds::of(TestId::of($test)), static fn(): TestDependencies => $read)],
    );
}

it('reads what a test method depends on in its own class, by each of PHPUnit\'s attributes and however they are imported', function (): void {
    $code = <<<'CODE'
        <?php

        namespace Tests;

        use PHPUnit\Framework\Attributes\Depends;
        use PHPUnit\Framework\Attributes as Unit;

        final class TokenTest extends TestCase
        {
            public function testBuilds(): string { return 'a'; }

            public function testSigns(): string { return 'b'; }

            #[Depends('testBuilds')]
            #[Unit\DependsUsingDeepClone(methodName: 'testSigns')]
            public function testParses(string $built, string $signed): void {}

            #[\PHPUnit\Framework\Attributes\DependsUsingShallowClone('testParses')]
            public function testVerifies(): void {}
        }
        CODE;

    expect(dependedOn($code, 'Tests\TokenTest::testVerifies'))->toBe([
        'Tests\TokenTest::testVerifies',
        'Tests\TokenTest::testParses',
        'Tests\TokenTest::testBuilds',
        'Tests\TokenTest::testSigns',
    ])->and(dependedOn($code, 'Tests\TokenTest::testParses#2'))->toBe([
        'Tests\TokenTest::testParses#2',
        'Tests\TokenTest::testBuilds',
        'Tests\TokenTest::testSigns',
    ]);
});

it('reads a dependency on another class\'s method, its class as the file names it', function (): void {
    $code = <<<'CODE'
        <?php

        namespace Tests;

        use PHPUnit\Framework\Attributes\DependsExternal;
        use PHPUnit\Framework\Attributes\DependsExternalUsingDeepClone;
        use App\Fixtures\KeysTest as Keys;

        final class TokenTest extends TestCase
        {
            #[DependsExternal(Keys::class, 'testMakes')]
            #[DependsExternalUsingDeepClone(className: \Other\ClockTest::class, methodName: 'testTicks')]
            public function testSigns(): void {}
        }
        CODE;

    expect(dependedOn($code, 'Tests\TokenTest::testSigns'))->toBe([
        'Tests\TokenTest::testSigns',
        'App\Fixtures\KeysTest::testMakes',
        'Other\ClockTest::testTicks',
    ]);
});

it('reads no dependency an attribute does not name by a literal, of a whole class, of another attribute, or off a method', function (): void {
    $code = <<<'CODE'
        <?php

        namespace Tests;

        use PHPUnit\Framework\Attributes\Depends;
        use PHPUnit\Framework\Attributes\DependsOnClass;
        use PHPUnit\Framework\Attributes\DependsExternal;
        use Other\Depends as Leans;

        #[Depends('testOnTheClass')]
        final class TokenTest extends TestCase
        {
            #[Depends(self::BUILDS)]
            #[DependsOnClass(KeysTest::class)]
            #[DependsExternal('Tests\KeysTest', 'testMakes')]
            #[Leans('testBuilds')]
            #[Depends]
            public function testSigns(): void {}
        }

        #[Depends('testBuilds')]
        function helper(): void {}
        CODE;

    expect(dependedOn($code, 'Tests\TokenTest::testSigns'))->toBe(['Tests\TokenTest::testSigns'])
        ->and(dependedOn('<?php final class Plain { public function testOne(): void {} }', 'Plain::testOne'))
        ->toBe(['Plain::testOne']);
});

it('reads what every one of some files records', function (): void {
    $files = [
        'tests/ATest.php' => "<?php\nuse PHPUnit\\Framework\\Attributes\\Depends;\nclass ATest { #[Depends('one')] public function two(): void {} }",
        'tests/BTest.php' => "<?php\nuse PHPUnit\\Framework\\Attributes\\Depends;\nclass BTest { #[Depends('three')] public function four(): void {} }",
    ];
    $read = DependsReader::inFiles(
        Paths::of(Path::of('tests/ATest.php'), Path::of('tests/BTest.php')),
        static fn(Path $file): Contents => Contents::of($files[$file->value()]),
    );
    $closure = static fn(string $test): array => array_map(
        static fn(TestId $id): string => $id->value(),
        [...TestDependencies::closure(TestIds::of(TestId::of($test)), static fn(): TestDependencies => $read)],
    );

    expect($closure('ATest::two'))->toBe(['ATest::two', 'ATest::one'])
        ->and($closure('BTest::four'))->toBe(['BTest::four', 'BTest::three']);
});
