<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\NegationTwins;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Mutators\ControlStructures\IfNegated;
use Pest\Mutate\Mutators\ControlStructures\TernaryNegated;
use Pest\Mutate\Mutators\Logical\RemoveNot;
use Pest\Mutate\Mutators\Logical\TrueToFalse;

afterEach(function (): void {
    Scratch::sweep();
});

const REMOVE_NOT = RemoveNot::class;

const IF_NEGATED = IfNegated::class;

const TERNARY_NEGATED = TernaryNegated::class;

/** A mutated copy holding this code, in a directory of its own. */
function negationCopy(string $code): string
{
    $directory = Scratch::directory();
    $path = sprintf('%s/%s.php', $directory, hash('sha256', $code));
    file_put_contents($path, $code);

    return $path;
}

it('branches alike on a value and on its value negated twice, whatever the value', function (Closure $value): void {
    $once = require negationCopy("<?php\nreturn static fn(mixed \$value): string => \$value ? 'then' : 'else';\n");
    $twice = require negationCopy("<?php\nreturn static fn(mixed \$value): string => !!\$value ? 'then' : 'else';\n");
    set_error_handler(static fn(): bool => true, E_WARNING);

    try {
        $negatedTwice = $twice instanceof Closure ? $twice($value()) : 'unread';
        $asIs = $once instanceof Closure ? $once($value()) : 'unread';
    } finally {
        restore_error_handler();
    }

    expect($negatedTwice)->toBe($asIs);
})->with([
    static fn(): int => 0,
    static fn(): int => 1,
    static fn(): int => -1,
    static fn(): float => 0.0,
    static fn(): float => 0.5,
    static fn(): string => '',
    static fn(): string => '0',
    static fn(): string => '00',
    static fn(): string => 'a',
    static fn(): array => [],
    static fn(): array => [0],
    static fn(): null => null,
    static fn(): bool => true,
    static fn(): bool => false,
    static fn(): stdClass => new stdClass(),
    static fn(): int => PHP_INT_MAX,
    static fn(): float => NAN,
]);

it('pairs a removed negation with the same condition negated again, in either order', function (string $negating): void {
    $removed = negationCopy("<?php\nif (\$ready) {\n    go();\n}\n");
    $negated = negationCopy("<?php\nif (!!\$ready) {\n    go();\n}\n");

    expect(NegationTwins::alike(REMOVE_NOT, $removed, $negating, $negated))->toBeTrue()
        ->and(NegationTwins::alike($negating, $negated, REMOVE_NOT, $removed))->toBeTrue();
})->with([IF_NEGATED, TERNARY_NEGATED]);

it('pairs nothing that differs anywhere else, nor any other mutators', function (): void {
    $removed = negationCopy("<?php\nif (\$ready) {\n    go();\n}\n");
    $elsewhere = negationCopy("<?php\nif (!!\$ready) {\n    stop();\n}\n");
    $wrapped = negationCopy("<?php\nif (!(\$ready)) {\n    go();\n}\n");

    expect(NegationTwins::alike(REMOVE_NOT, $removed, IF_NEGATED, $elsewhere))->toBeFalse()
        ->and(NegationTwins::alike(REMOVE_NOT, $removed, IF_NEGATED, $wrapped))->toBeFalse()
        ->and(NegationTwins::alike(REMOVE_NOT, $removed, REMOVE_NOT, $removed))->toBeFalse()
        ->and(NegationTwins::alike(IF_NEGATED, $removed, TERNARY_NEGATED, $removed))->toBeFalse()
        ->and(NegationTwins::names(TrueToFalse::class))->toBeFalse();
});
