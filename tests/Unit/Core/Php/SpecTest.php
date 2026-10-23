<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Php\Spec;
use NightWorksIO\MutationGate\Core\Php\Tokens;

/** The spec a file's text declares, or nothing. */
function specOf(string $text): Spec|Nameless
{
    return Spec::in(Tokens::in(Contents::of($text)));
}

it('reads the first named class as a spec where it extends ObjectBehavior, however it is named', function (string $declared): void {
    expect(specOf(sprintf("<?php\n\n\$fake = new class {};\n\n%s\n{\n}\n", $declared)))->toBeInstanceOf(Spec::class);
})->with([
    'by its short name' => ['final class CartSpec extends ObjectBehavior'],
    'qualified' => ['final class CartSpec extends PhpSpec\ObjectBehavior'],
    'fully qualified' => ['final class CartSpec extends \PhpSpec\ObjectBehavior'],
]);

it('reads no spec where the first named class extends another, nothing, or there is none', function (string $text): void {
    expect(specOf($text))->toEqual(Nameless::code());
})->with([
    'another parent' => ["<?php\n\nfinal class CartTest extends TestCase\n{\n}\n\nfinal class CartSpec extends ObjectBehavior\n{\n}\n"],
    'a parent named like it' => ["<?php\n\nfinal class CartSpec extends MyObjectBehavior\n{\n}\n"],
    'a parent of its own namespace' => ["<?php\n\nfinal class CartSpec extends namespace\\ObjectBehavior\n{\n}\n"],
    'an interface' => ["<?php\n\nfinal class CartSpec implements ObjectBehavior\n{\n}\n"],
    'no parent' => ["<?php\n\nfinal class CartSpec\n{\n}\n"],
    'no class' => ["<?php\n\nit('adds', fn () => true);\n"],
]);

it('names the matchers of its own its getMatchers() keys, in any case, and none it names elsewhere or deeper', function (): void {
    $spec = specOf(<<<'PHP'
        <?php

        final class CartSpec extends ObjectBehavior
        {
            public function it_adds(): void
            {
                $this->totals(['beTotal' => 3]);
            }

            public function getMatchers(): array
            {
                return ['beMoney' => fn($subject): bool => true, 'haveCoins' => fn($subject): bool => $subject === ['beNested' => 1]];
            }
        }
        PHP);

    expect($spec instanceof Spec ? array_map($spec->hasMatcher(...), ['bemoney', 'HaveCoins', 'beTotal', 'beNested']) : [])
        ->toBe([true, true, false, false]);
});

it('names no matcher of its own where it declares no getMatchers()', function (): void {
    $spec = specOf("<?php\n\nfinal class CartSpec extends ObjectBehavior\n{\n    public function it_adds(): void\n    {\n        \$this->totals(['beMoney' => 3]);\n    }\n}\n");

    expect($spec instanceof Spec && $spec->hasMatcher('beMoney'))->toBeFalse();
});
