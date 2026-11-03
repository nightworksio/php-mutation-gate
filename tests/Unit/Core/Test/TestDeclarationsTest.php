<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestDeclarations;
use NightWorksIO\MutationGate\Core\Test\TestMethod;

/** A PHPUnit test class with two tests, a hook, a helper and a data provider. */
const DECLARED_CLASS = <<<'PHP_WRAP'
<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    private Money $money;

    protected function setUp(): void
    {
        $this->money = new Money(1);
    }

    /** It adds. */
    #[DataProvider('amounts')]
    public function testAdds(int $amount): void
    {
        $this->check($this->money->add($amount));
    }

    public function testRounds(): void
    {
        $this->check($this->rounded());
    }

    public static function amounts(): array
    {
        return [[1]];
    }

    private function rounded(): int
    {
        return $this->money->round();
    }
}

PHP_WRAP;

/** A Pest test file with tests at its top and in two depths of `describe()`, a hook and a helper. */
const DECLARED_PEST = <<<'PHP'
    <?php

    use App\Money;

    function money(): Money
    {
        return new Money(1);
    }

    it('adds', function (): void {
        expect(money()->add(1))->toBe(2);
    })->with([1, 2])->group('money');

    test('subtracts', fn () => expect(money()->subtract(1))->toBe(0));

    describe('Money', function (): void {
        beforeEach(function (): void {
            $this->money = money();
        });

        it('rounds', fn () => expect($this->money->round())->toBe(1));

        describe('in cents', function (): void {
            \it('carries', fn () => expect(true)->toBeTrue());
        });
    });

    PHP;

it('finds each test method of a named class, from its comments and attributes to the end of its body', function (): void {
    $declared = TestDeclarations::in(Contents::of(DECLARED_CLASS));

    expect($declared->of('testAdds'))->toBe(<<<'PHP'


            /** It adds. */
            #[DataProvider('amounts')]
            public function testAdds(int $amount): void
            {
                $this->check($this->money->add($amount));
            }
        PHP)
        ->and($declared->of('testRounds'))->toBe(<<<'PHP'


            public function testRounds(): void
            {
                $this->check($this->rounded());
            }
        PHP)
        ->and($declared->of('setUp'))->toBeString()
        ->and($declared->of('testDivides'))->toEqual(NotGiven::value())
        ->and($declared->dependent())->toBeFalse();
});

it('leaves what the tests share without each test it is asked about', function (): void {
    $shared = TestDeclarations::in(Contents::of(DECLARED_CLASS))->shared(['testAdds', 'testRounds']);

    expect($shared)->toContain("{\n        \$this->money = new Money(1);\n    }\n\n    public static function amounts()")
        ->and($shared)->toStartWith("<?php\n\nuse PHPUnit\\Framework\\Attributes\\DataProvider;")
        ->and($shared)->toEndWith("return \$this->money->round();\n    }\n}\n")
        ->and($shared)->not->toContain('testAdds(int')
        ->and(TestDeclarations::in(Contents::of(DECLARED_CLASS))->shared([]))->toBe(DECLARED_CLASS);
});

it('finds each Pest test by the method Pest makes of its description under each describe around it', function (): void {
    $declared = TestDeclarations::in(Contents::of(DECLARED_PEST));

    expect($declared->of(TestMethod::pest('it adds')))->toBe(<<<'PHP'


        it('adds', function (): void {
            expect(money()->add(1))->toBe(2);
        })->with([1, 2])->group('money');
        PHP)
        ->and($declared->of(TestMethod::pest('subtracts')))
        ->toBe("\n\ntest('subtracts', fn () => expect(money()->subtract(1))->toBe(0));")
        ->and($declared->of(TestMethod::pest('`Money` → it rounds')))
        ->toBe("\n\n    it('rounds', fn () => expect(\$this->money->round())->toBe(1));")
        ->and($declared->of(TestMethod::pest('`Money` → `in cents` → it carries')))
        ->toBe("\n        \\it('carries', fn () => expect(true)->toBeTrue());")
        ->and($declared->of(TestMethod::pest('it rounds')))->toEqual(NotGiven::value())
        ->and($declared->of('money'))->toEqual(NotGiven::value());
});

it('leaves a hook, a helper and the describe around a test among what the tests share', function (): void {
    $methods = [
        TestMethod::pest('it adds'),
        TestMethod::pest('subtracts'),
        TestMethod::pest('`Money` → it rounds'),
        TestMethod::pest('`Money` → `in cents` → it carries'),
    ];
    $shared = TestDeclarations::in(Contents::of(DECLARED_PEST))->shared($methods);

    expect($shared)->toContain('function money(): Money')
        ->and($shared)->toContain("beforeEach(function (): void {\n        \$this->money = money();\n    });")
        ->and($shared)->toContain("describe('in cents', function (): void {\n    });")
        ->and($shared)->not->toContain('toBeTrue()')
        ->and($shared)->not->toContain('subtract(1)');
});

it('reads a description as PHP reads its literal, and the test at the very start of a file', function (): void {
    $declared = TestDeclarations::in(Contents::of(<<<'PHP'
        <?php it('can\'t lose a \\ cent', fn () => 1);
        test("tabs\tand \"quotes\" \$here", fn () => 2);
        PHP));

    expect($declared->of(TestMethod::pest("it can't lose a \\ cent")))->toBe("<?php it('can\\'t lose a \\\\ cent', fn () => 1);")
        ->and($declared->of(TestMethod::pest("tabs\tand \"quotes\" \$here")))->toBe("\ntest(\"tabs\\tand \\\"quotes\\\" \\\$here\", fn () => 2);");
});

it('reads a single-quoted description\'s other backslashes as they are written, and starts a test after the statement before it', function (): void {
    $declared = TestDeclarations::in(Contents::of("<?php ;it('keeps \\n and \\t', fn () => 1);"));

    expect($declared->of(TestMethod::pest('it keeps \\n and \\t')))->toBe("it('keeps \\n and \\t', fn () => 1);");
});

it('finds no test whose description is built as it runs, or that stands in anything but a describe', function (): void {
    $declared = TestDeclarations::in(Contents::of(<<<'PHP'
        <?php
        $name = 'money';
        it('adds ' . $name, fn () => 1);
        it("adds {$name}", fn () => 1);
        it(sprintf('adds %s', $name), fn () => 1);
        describe($name, function (): void {
            it('rounds', fn () => 1);
        });
        foreach ([1] as $each) {
            it('carries', fn () => 1);
        }
        dataset('amounts', function () {
            it('splits', fn () => 1);
        });
        $money->it('pays', fn () => 1);
        PHP));

    expect($declared->of(TestMethod::pest('it adds ')))->toEqual(NotGiven::value())
        ->and($declared->of(TestMethod::pest('it adds money')))->toEqual(NotGiven::value())
        ->and($declared->of(TestMethod::pest('it rounds')))->toEqual(NotGiven::value())
        ->and($declared->of(TestMethod::pest('`money` → it rounds')))->toEqual(NotGiven::value())
        ->and($declared->of(TestMethod::pest('it carries')))->toEqual(NotGiven::value())
        ->and($declared->of(TestMethod::pest('it splits')))->toEqual(NotGiven::value())
        ->and($declared->of(TestMethod::pest('it pays')))->toEqual(NotGiven::value());
});

it('finds no method of an anonymous class, a function or an interface, nor one two classes declare', function (): void {
    $declared = TestDeclarations::in(Contents::of(<<<'PHP'
        <?php
        interface Pays
        {
            public function testPays(): void;
        }

        function testHelps(): void
        {
        }

        final class OneTest
        {
            public function testTwice(): void
            {
            }

            public function testOnce(): object
            {
                return new class {
                    public function testInside(): void
                    {
                    }
                };
            }
        }

        final class TwoTest
        {
            public function testTwice(): void
            {
            }

            public function testAgain(): void
            {
            }
        }

        final class ThreeTest
        {
            public function testAgain(): void
            {
            }
        }
        PHP));

    expect($declared->of('testPays'))->toEqual(NotGiven::value())
        ->and($declared->of('testHelps'))->toEqual(NotGiven::value())
        ->and($declared->of('testInside'))->toEqual(NotGiven::value())
        ->and($declared->of('testTwice'))->toEqual(NotGiven::value())
        ->and($declared->of('testAgain'))->toEqual(NotGiven::value())
        ->and($declared->of('testOnce'))->toBeString();
});

it('says a test may depend on another where a Pest test chains depends, or an attribute or annotation declares it', function (string $code, bool $dependent): void {
    expect(TestDeclarations::in(Contents::of($code))->dependent())->toBe($dependent);
})->with([
    'a chained depends' => ["<?php\nit('adds', fn () => 1)->depends('it pays');\n", true],
    'an attribute' => ["<?php\nfinal class AT {\n#[PHPUnit\\Framework\\Attributes\\DependsExternal(B::class, 'testA')]\npublic function testB(): void {}\n}\n", true],
    'an attribute among others' => ["<?php\nfinal class AT {\n#[Test, Depends('testA')]\npublic function b(): void {}\n}\n", true],
    'an annotation' => ["<?php\nfinal class AT {\n/** @depends testA */\npublic function testB(): void {}\n}\n", true],
    'a description that says depends' => ["<?php\nit('depends on nothing', fn () => depends());\n", false],
    'a method of that name called alone' => ["<?php\nit('adds', fn () => Money::depends());\n", false],
    'no word of it' => ["<?php\nit('adds', fn () => 1);\n", false],
]);

it('finds a method of a class the file opens with, and leaves out each test in the order they stand', function (): void {
    $first = TestDeclarations::in(Contents::of("<?php final class FirstTest { public function testFirst(): void {} }\n"));
    $mixed = TestDeclarations::in(Contents::of(<<<'PHP'
        <?php
        it('adds', fn () => 1);

        final class Helper
        {
            public function testHelps(): void
            {
            }
        }
        PHP));

    expect($first->of('testFirst'))->toBe(' public function testFirst(): void {}')
        ->and($mixed->shared([TestMethod::pest('it adds'), 'testHelps']))->toBe("\n\nfinal class Helper\n{\n}");
});
