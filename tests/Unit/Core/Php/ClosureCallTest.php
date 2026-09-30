<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hold\Standing;
use NightWorksIO\MutationGate\Core\Php\ClosureCall;
use NightWorksIO\MutationGate\Core\Php\Tokens;

$standing = static function (string $code): Standing {
    $tokens = Tokens::of(array_values(array_filter(
        PhpToken::tokenize(sprintf("<?php\n%s\n", $code)),
        static fn(PhpToken $token): bool => ! $token->isIgnorable(),
    )));

    return ClosureCall::standingOf($tokens, $tokens->indicesOf(T_ATTRIBUTE)[0]);
};

it('stands a closure passed to a test on the test', function (string $code) use ($standing): void {
    expect($standing($code))->toBe(Standing::TestClosure);
})->with([
    'it()' => ["it('boots', #[Holds('src/Kernel.php')] function (): void {});"],
    'test()' => ["test('boots', #[Holds('src/Kernel.php')] fn () => true);"],
    'arch()' => ["arch('layers', #[Holds('src/Core')] static fn () => true);"],
    'it(), fully qualified' => ["\\it('boots', #[Holds('src/Kernel.php')] static function () {});"],
    'test(), in any case' => ["Test('boots', #[Holds('src/Kernel.php')] fn () => true);"],
]);

it('stands a closure passed to describe on every test inside it', function () use ($standing): void {
    expect($standing("describe('the kernel', #[Holds('src/Kernel')] function (): void {});"))
        ->toBe(Standing::DescribeClosure);
});

it('stands a closure passed to a hook on the hook', function (string $code) use ($standing): void {
    expect($standing($code))->toBe(Standing::HookClosure);
})->with([
    'beforeEach()' => ["beforeEach(#[Holds('src/Kernel.php')] function (): void {});"],
    'afterEach()' => ["afterEach(#[Holds('src/Kernel.php')] fn () => null);"],
    'beforeAll()' => ["beforeAll(#[Holds('src/Kernel.php')] fn () => null);"],
    'afterAll()' => ["afterAll(#[Holds('src/Kernel.php')] fn () => null);"],
    '->beforeEach()' => ["pest()->beforeEach(#[Holds('src/Kernel.php')] fn () => null);"],
    '->afterEach()' => ["uses()->afterEach(#[Holds('src/Kernel.php')] fn () => null);"],
    '?->beforeAll()' => ["\$suite?->beforeAll(#[Holds('src/Kernel.php')] fn () => null);"],
    '::afterAll()' => ["Suite::afterAll(#[Holds('src/Kernel.php')] fn () => null);"],
]);

it('stands a closure that gives a dataset on the dataset', function (string $code) use ($standing): void {
    expect($standing($code))->toBe(Standing::DatasetClosure);
})->with([
    'dataset()' => ["dataset('rows', #[Holds('src/Kernel.php')] fn () => [1]);"],
    '->with()' => ["it('adds', fn () => true)->with(#[Holds('src/Kernel.php')] fn () => [1]);"],
    '?->with()' => ["\$suite?->with(#[Holds('src/Kernel.php')] fn () => [1]);"],
    'in an array' => ["it('adds', fn () => true)->with([#[Holds('src/Kernel.php')] fn () => 1]);"],
    'by a key, arrays deep' => ["it('adds', fn () => 1)->with(['one' => [[#[Holds('src/Kernel.php')] fn () => 1]]]);"],
]);

it('stands a closure assigned on what it is kept in', function () use ($standing): void {
    expect($standing("\$test = #[Holds('src/Kernel.php')] fn () => true;"))->toBe(Standing::KeptClosure);
});

it('stands any other closure on itself', function (string $code) use ($standing): void {
    expect($standing($code))->toBe(Standing::OtherClosure);
})->with([
    'passed to another function' => ["array_map(#[Holds('src/Kernel.php')] fn (\$x) => \$x, []);"],
    'returned' => ["it('runs', function () { return #[Holds('src/Kernel.php')] fn () => 1; });"],
    'in an array kept' => ["\$rows = [#[Holds('src/Kernel.php')] fn () => 1];"],
    'passed to a method named as a test' => ["Suite::it('boots', #[Holds('src/Kernel.php')] fn () => 1);"],
    'passed to a function named as a test elsewhere' => ["Pest\\it('boots', #[Holds('src/Kernel.php')] fn () => 1);"],
    'passed to another method' => ["\$rows->each(#[Holds('src/Kernel.php')] fn () => 1);"],
    'called at once' => ["(#[Holds('src/Kernel.php')] fn () => 1)();"],
    'first in the file' => ["#[Holds('src/Kernel.php')] fn () => 1;"],
]);
