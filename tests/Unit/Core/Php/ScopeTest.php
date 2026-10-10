<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\Scope;

/** The significant tokens of a statement. */
$tokens = static fn(string $statement): array => array_values(array_filter(
    PhpToken::tokenize(sprintf('<?php %s', $statement)),
    static fn(PhpToken $token): bool => ! $token->isIgnorable(),
));

it('declares a name in the global namespace as it is written', function (): void {
    expect(Scope::global()->declared('Money'))->toBe('Money');
});

it('declares a name inside its namespace', function (): void {
    expect(Scope::global()->inside('App\Domain')->declared('Money'))->toBe('App\Domain\Money');
});

it('reads a fully qualified name as itself', function (): void {
    $names = Scope::global()->inside('Tests')->resolve('\App\Money');

    expect($names->meet(Names::of('App\Money')))->toBeTrue()
        ->and($names->meet(Names::of('Tests\App\Money')))->toBeFalse();
});

it('reads a name relative to the namespace inside it', function (string $name): void {
    $names = Scope::global()->inside('App')->resolve($name);

    expect($names->meet(Names::of('App\Domain\Money')))->toBeTrue()
        ->and($names->meet(Names::of('Domain\Money')))->toBeFalse();
})->with(['namespace\Domain\Money', 'Namespace\Domain\Money']);

it('reads a name it does not import as the namespace\'s own or a global function', function (): void {
    $names = Scope::global()->inside('App')->resolve('format');

    expect($names->meet(Names::of('App\format')))->toBeTrue()
        ->and($names->meet(Names::of('format')))->toBeTrue()
        ->and($names->meet(Names::of('Other\format')))->toBeFalse();
});

it('reads an imported name as what it imports, and as nothing else', function () use ($tokens): void {
    $scope = Scope::global()->inside('Tests')->importing(...$tokens('use App\Domain\Money;'));

    expect($scope->resolve('Money')->meet(Names::of('App\Domain\Money')))->toBeTrue()
        ->and($scope->resolve('money')->meet(Names::of('App\Domain\Money')))->toBeTrue()
        ->and($scope->resolve('Money')->meet(Names::of('Tests\Money', 'Money')))->toBeFalse();
});

it('reads an alias as what it imports', function () use ($tokens): void {
    $scope = Scope::global()->importing(...$tokens('use App\Domain\Money as Cash;'));

    expect($scope->resolve('Cash')->meet(Names::of('App\Domain\Money')))->toBeTrue()
        ->and($scope->resolve('Money')->meet(Names::of('App\Domain\Money')))->toBeFalse();
});

it('reads a name qualified by an import through what it imports', function () use ($tokens): void {
    $scope = Scope::global()->inside('Tests')->importing(...$tokens('use App\Domain;'));

    expect($scope->resolve('Domain\Money')->meet(Names::of('App\Domain\Money')))->toBeTrue()
        ->and($scope->resolve('Domain\Ledger\Entry')->meet(Names::of('App\Domain\Ledger\Entry')))->toBeTrue()
        ->and($scope->resolve('Domain\Money')->meet(Names::of('Tests\Domain\Money')))->toBeFalse();
});

it('reads every name a statement imports, listed or grouped', function () use ($tokens): void {
    $scope = Scope::global()
        ->importing(...$tokens('use App\Money, App\Clock;'))
        ->importing(...$tokens('use App\Domain\{Ledger, Entry as Line, };'));

    expect($scope->resolve('Money')->meet(Names::of('App\Money')))->toBeTrue()
        ->and($scope->resolve('Clock')->meet(Names::of('App\Clock')))->toBeTrue()
        ->and($scope->resolve('Ledger')->meet(Names::of('App\Domain\Ledger')))->toBeTrue()
        ->and($scope->resolve('Line')->meet(Names::of('App\Domain\Entry')))->toBeTrue();
});

it('reads the functions and constants a statement imports, and a name imported with a leading separator', function () use ($tokens): void {
    $scope = Scope::global()
        ->importing(...$tokens('use function App\Support\format;'))
        ->importing(...$tokens('use const App\Support\LIMIT;'))
        ->importing(...$tokens('use \App\Clock;'));

    expect($scope->resolve('format')->meet(Names::of('App\Support\format')))->toBeTrue()
        ->and($scope->resolve('LIMIT')->meet(Names::of('App\Support\LIMIT')))->toBeTrue()
        ->and($scope->resolve('Clock')->meet(Names::of('App\Clock')))->toBeTrue();
});

it('keeps its namespace and its imports as it goes', function () use ($tokens): void {
    $scope = Scope::global()->inside('App')->importing(...$tokens('use Lib\Clock;'));

    expect($scope->inside('Other')->resolve('Clock')->meet(Names::of('Lib\Clock')))->toBeTrue()
        ->and($scope->importing(...$tokens('use App\Money;'))->declared('Ledger'))->toBe('App\Ledger')
        ->and($scope->importing(...$tokens('use App\Money;'))->resolve('Clock')->meet(Names::of('Lib\Clock')))->toBeTrue();
});

it('reads a group with no prefix as names of their own', function () use ($tokens): void {
    $scope = Scope::global()->inside('App')->importing(...$tokens('use {Money};'));

    expect($scope->resolve('Money')->meet(Names::of('Money')))->toBeTrue()
        ->and($scope->resolve('Money')->meet(Names::of('App\Money')))->toBeFalse();
});

it('reads a name that a class and a function are both imported as as the one imported last, or as either of any kind', function () use ($tokens): void {
    $scope = Scope::global()->inside('Billing')
        ->importing(...$tokens('use App\Equals;'))
        ->importing(...$tokens('use function Lib\equals;'));

    expect($scope->resolve('Equals')->all())->toBe(['lib\equals'])
        ->and($scope->resolveAny('Equals')->all())->toBe(['app\equals', 'lib\equals'])
        ->and($scope->resolveAny('Equals\Same')->all())->toBe(['app\equals\same', 'lib\equals\same'])
        ->and($scope->resolveAny('Money')->all())->toBe(['billing\money', 'money'])
        ->and($scope->resolveAny('\Lib\Money')->all())->toBe(['lib\money'])
        ->and($scope->importing(...$tokens('use Lib\Clock;'))->resolveAny('Clock')->all())->toBe(['lib\clock']);
});

it('names a class as the file spells it: fully qualified, relative, imported or the namespace\'s own', function () use ($tokens): void {
    $scope = Scope::global()->inside('Tests')->importing(...$tokens('use App\Domain\Money as Cash;'));

    expect($scope->className('\App\Order'))->toBe('App\Order')
        ->and($scope->className('namespace\Unit\Cart'))->toBe('Tests\Unit\Cart')
        ->and($scope->className('Cash'))->toBe('App\Domain\Money')
        ->and($scope->className('Cash\Rate'))->toBe('App\Domain\Money\Rate')
        ->and($scope->className('CartTest'))->toBe('Tests\CartTest');
});
