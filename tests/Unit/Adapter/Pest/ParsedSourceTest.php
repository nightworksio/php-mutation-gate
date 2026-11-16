<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\ParsedSource;
use Pest\Mutate\Mutators\Arithmetic\MinusToPlus;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;
use Pest\Mutate\Mutators\Array\ArrayKeyFirstToArrayKeyLast;
use Pest\Mutate\Mutators\Logical\FalseToTrue;
use Pest\Mutate\Mutators\Logical\TrueToFalse;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Return_;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * A parse by PHP's newest version, and how often it parsed each code, by the code.
 *
 * @return array{Closure(string): array<Stmt>, Closure(): array<string, int>}
 */
function countingParse(): array
{
    $parser = new ParserFactory()->createForNewestSupportedVersion();
    $parsed = [];

    return [
        static function (string $code) use (&$parsed, $parser): array {
            $parsed[$code] = array_key_exists($code, $parsed) ? $parsed[$code] + 1 : 1;

            return $parser->parse($code) ?? [];
        },
        static function () use (&$parsed): array {
            return $parsed;
        },
    ];
}

/**
 * Whether one of the mutators these tests ask takes a node, as Pest asks it.
 *
 * @return Closure(string, Node): bool
 */
function pestAsks(): Closure
{
    return static fn(string $mutator, Node $node): bool => match ($mutator) {
        MinusToPlus::class => MinusToPlus::can($node),
        PlusToMinus::class => PlusToMinus::can($node),
        FalseToTrue::class => FalseToTrue::can($node),
        TrueToFalse::class => TrueToFalse::can($node),
        ArrayKeyFirstToArrayKeyLast::class => ArrayKeyFirstToArrayKeyLast::can($node),
        default => false,
    };
}

it('hands each attempt a copy of the code\'s one parse, as a fresh parse gives it, which it may change', function (): void {
    [$parse, $parsed] = countingParse();
    $code = "<?php\n\nreturn 1 + 2;\n";
    $first = ParsedSource::statements($parse, $code);
    $return = $first[0];
    $return instanceof Return_ && $return->expr instanceof Plus ? $return->expr = new Minus($return->expr->left, $return->expr->right) : null;
    $second = ParsedSource::statements($parse, $code);

    expect($second)->toEqual(new ParserFactory()->createForNewestSupportedVersion()->parse($code))
        ->and($second[0])->not->toBe($first[0])
        ->and($parsed())->toBe([$code => 1]);
});

it('parses another code afresh, and the first again once another came between', function (): void {
    [$parse, $parsed] = countingParse();
    $one = "<?php\n\nreturn 1;\n";
    $two = "<?php\n\nreturn 2;\n";
    ParsedSource::statements($parse, $one);
    $other = ParsedSource::statements($parse, $two);
    ParsedSource::statements($parse, $one);

    expect(new Standard()->prettyPrintFile($other))->toBe("<?php\n\nreturn 2;")
        ->and($parsed())->toBe([$one => 2, $two => 1]);
});

it('prints the code once for every mutant of it, as Pest prints a mutant\'s file, and another code afresh', function (): void {
    [$parse, $parsed] = countingParse();
    $one = "<?php\nreturn   1+2 ;\n";
    $two = "<?php\nreturn 3;\n";
    $printed = [ParsedSource::printed($parse, $one), ParsedSource::printed($parse, $one), ParsedSource::printed($parse, $two), ParsedSource::printed($parse, $one)];

    expect($printed)->toBe(["<?php\n\nreturn 1 + 2;", "<?php\n\nreturn 1 + 2;", "<?php\n\nreturn 3;", "<?php\n\nreturn 1 + 2;"])
        ->and($parsed())->toBe([$one => 2, $two => 1]);
});

it('keeps the mutators some node of the code takes, by its class or by more, in their order and keys, and parses the code once', function (): void {
    [$parse, $parsed] = countingParse();
    $code = "<?php\n\nfunction f(\$a) { return \$a + 1 > 0 ? false : array_key_first(\$a); }\n";
    $mutators = [3 => MinusToPlus::class, 5 => FalseToTrue::class, 7 => PlusToMinus::class, 9 => TrueToFalse::class, 11 => ArrayKeyFirstToArrayKeyLast::class];

    expect(ParsedSource::taking($mutators, pestAsks(), $parse, $code))->toBe([5 => FalseToTrue::class, 7 => PlusToMinus::class, 11 => ArrayKeyFirstToArrayKeyLast::class])
        ->and(ParsedSource::statements($parse, $code))->toEqual(new ParserFactory()->createForNewestSupportedVersion()->parse($code))
        ->and($parsed())->toBe([$code => 1]);
});

it('keeps no mutator for a code no node of which any takes', function (): void {
    [$parse] = countingParse();

    expect(ParsedSource::taking([PlusToMinus::class, FalseToTrue::class], pestAsks(), $parse, "<?php\n\nreturn true;\n"))->toBe([]);
});

it('has no statements, and prints none, for a code its parse gives nothing for', function (): void {
    $nothing = static fn(): null => null;

    expect(ParsedSource::statements($nothing, '<?php broken'))->toBe([])
        ->and(ParsedSource::printed($nothing, '<?php broken'))->toBe(new Standard()->prettyPrintFile([]));
});
