<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\PHPStan\Rules\SharedLiterals\Shared;

/**
 * A literal handed to a parameter, as the collector writes it.
 *
 * @return array{method: string, parameter: string, literal: string, caller: string, file: string, line: int}
 */
function handed(string $method, string $parameter, string $literal, string $caller): array
{
    return ['method' => $method, 'parameter' => $parameter, 'literal' => $literal, 'caller' => $caller, 'file' => '/src/Words.php', 'line' => 9];
}

it('groups the literals by parameter, keeping those two or more classes hand one', function (): void {
    $shared = Shared::among([
        '/src/First.php' => [[handed('Words::say', 'word', "'hello'", 'First'), handed('Words::say', 'tone', "'loud'", 'First')]],
        '/src/Second.php' => [[handed('Words::say', 'word', "'bye'", 'Second')], [handed('Words::say', 'word', "'hello'", 'Second')]],
    ], []);

    expect(array_map(static fn(Shared $one): array => [$one->method, $one->parameter, $one->file, $one->line, $one->callers, $one->literals], $shared))
        ->toBe([['Words::say', 'word', '/src/Words.php', 9, ['First', 'Second'], ["'bye'", "'hello'"]]]);
});

it('counts the classes that hand a parameter literals, not the calls', function (): void {
    expect(Shared::among(['/src/First.php' => [[handed('Words::say', 'word', "'a'", 'First'), handed('Words::say', 'word', "'b'", 'First')]]], []))
        ->toBe([]);
});

it('leaves out an allowed method, and names an allowed one no two classes need', function (): void {
    $collected = [
        '/src/First.php' => [[handed('Words::say', 'word', "'a'", 'First'), handed('Quiet::say', 'word', "'b'", 'First')]],
        '/src/Second.php' => [[handed('Words::say', 'word', "'c'", 'Second')]],
    ];

    expect(Shared::among($collected, ['Words::say']))->toBe([])
        ->and(Shared::unneeded($collected, ['Words::say', 'Quiet::say', 'Gone::say']))->toBe(['Quiet::say', 'Gone::say']);
});
