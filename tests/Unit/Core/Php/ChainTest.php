<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Chain;
use NightWorksIO\MutationGate\Core\Php\Link;

/** A statement, by how it is written, read as a chain. */
function chainOf(string $written): Chain
{
    $tokens = [];

    foreach (PhpToken::tokenize(sprintf('<?php %s', $written)) as $token) {
        if (! $token->isIgnorable()) {
            $tokens[] = $token;
        }
    }

    return Chain::of($tokens);
}

it('reads a function\'s call and the methods chained on it, each with its arguments', function (): void {
    $chain = chainOf("\\IT('adds', fn () => 1, )->with([1, 2])->not->group('a', 'b');");
    $named = static fn(Link $link): string => sprintf('%s/%d', $link->name(), count($link->arguments()));

    expect($chain->isChain())->toBeTrue()
        ->and($named($chain->called()))->toBe('it/2')
        ->and(array_map($named, $chain->methods()))->toBe(['with/1', 'not/0', 'group/2']);
});

it('reads a call with no arguments as having none', function (): void {
    expect(chainOf('uses();')->called()->arguments())->toBe([]);
});

it('reads any other statement as no chain', function (string $written): void {
    expect(chainOf($written)->isChain())->toBeFalse();
})->with([
    'a name alone' => ['it;'],
    'a chain with no end' => ["it('adds')->only"],
    'a call not ended' => ["it('adds') {}"],
    'more after the chain' => ["it('adds') || putenv('A=1');"],
    'a nullsafe call' => ["it('adds')?->group('a');"],
    'a static call on what it returns' => ["it('adds')::group('a');"],
    'a qualified function' => ["Pest\\it('adds');"],
    'an assignment' => ["\$a = it('adds');"],
    'a call of a call' => ["it('adds')('b');"],
]);
