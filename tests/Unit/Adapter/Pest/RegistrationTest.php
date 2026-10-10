<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Registration;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Php\PhpFile;

$inert = static fn(string $top): bool => Registration::inert(
    PhpFile::read(Contents::of(sprintf("<?php\ndeclare(strict_types=1);\nnamespace Tests;\nuse Library\\Money;\n%s\n", $top))),
);

it('holds a test file inert whose top only declares, or registers what stays in the file', function (string $top) use ($inert): void {
    expect($inert($top))->toBeTrue();
})->with([
    'declarations alone' => ['const LIMIT = 5; function helper(): int { return 1; } final class Fake {} trait Shared {} enum Kind {} interface Seen {}'],
    'a test' => ["test('adds', function (): void { expect(1)->toBe(1); });"],
    'an it' => ["it('adds', function (): void { expect(1)->toBe(1); })->with([1, 2])->group('money')->skip(false);"],
    'a describe' => ["describe('money', function (): void { it('adds', fn () => 1); beforeEach(fn () => 1); });"],
    'a beforeEach' => ['beforeEach(function (): void { $this->money = new Money(); });'],
    'an afterEach' => ['afterEach(function (): void { $this->money = null; });'],
    'a todo' => ["todo('subtracts');"],
    'an arch test' => ["arch('keeps money final')->expect(Money::class)->toBeFinal();"],
    'a beforeAll' => ['beforeAll(function (): void { Money::reset(); });'],
    'an afterAll' => ['afterAll(function (): void { Money::reset(); });'],
    'a dataset of the file' => ["dataset('amounts', [1, 2]);"],
    'the code the file covers' => ['covers(Money::class);'],
    'a trait the file\'s tests use' => ['uses(Shared::class)->group(\'money\');'],
    'a registration spelt fully qualified, or in another case' => ["\\it('adds', fn () => 1); IT('subtracts', fn () => 1);"],
    'a method named in inside the registration' => ["it('fits', function (): void { expect(1)->not->toBeIn([2]); });"],
    'a describe nesting registrations' => ["describe('money', function (): void { describe('adds', fn () => it('sums', fn () => 1)); beforeEach(fn () => 1); });"],
    'a dataset a closure returns' => ["dataset('amounts', function (): array { return [[1], [2]]; });"],
    'a dataset a generator yields' => ["dataset('amounts', function (): iterable { yield 'one' => [1]; yield [2]; });"],
    'a dataset an arrow function returns' => ["dataset('amounts', fn (): array => [[1], [2]]);"],
    'a dataset whose rows are closures, which run as the test runs' => ["dataset('amounts', [fn () => new Money()]);"],
    'a dataset with() takes as a closure' => ["it('adds', fn (int \$a) => \$a)->with(fn (): array => [[1]]);"],
    'arguments of constants, class names and operators' => ["it('adds' . PHP_EOL, fn () => 1)->skip(PHP_OS_FAMILY === 'Windows' || ! true)->group(Money::class)->with([[1 => -2, 'k' => Money::LIMIT]]);"],
    'a test closure with an attribute' => ["it('adds', #[Holds('src/Money.php')] static fn () => 1);"],
    'registrations in a namespace\'s block' => ["namespace Tests\\Unit { it('adds', fn () => 1); }"],
    'a variable of its own assigned a closure' => ["\$make = static fn (): int => 1; it('adds', fn () => \$make());"],
    'a variable of its own assigned a closure with a body, which ends the statement' => ["\$make = static function () use (\$rows): int { foreach (\$rows as \$row) {} return 1; }; it('adds', fn () => \$make());"],
    'a variable of its own assigned literals, constants and class names' => ["\$rows = [[1, Money::LIMIT], ['k' => PHP_EOL . Money::class]];"],
    'a variable of its own assigned in a describe body' => ["describe('money', function (): void { \$make = fn () => 1; it('adds', fn () => 1); });"],
    'a variable of its own handed to with()' => ["\$rows = [[1], [2]]; it('adds', fn (int \$a) => \$a)->with(\$rows);"],
    'a variable of its own given by a dataset closure' => ["it('adds', fn (int \$a) => \$a)->with(fn (): array => \$rows); \$rows = [[1]];"],
    'a variable of its own assigned others of its own' => ["\$all = [...\$cheap, ...\$dear]; \$cheap = [[1]]; \$dear = [[2]]; dataset('prices', \$all);"],
]);

it('holds a test file acting, and loaded in every narrowed run, whose top runs anything else', function (string $top) use ($inert): void {
    expect($inert($top))->toBeFalse();
})->with([
    'a hook pest() registers for a directory' => ["pest()->beforeEach(fn () => 1)->in(__DIR__);"],
    'a trait uses() adds to a directory' => ["uses(Shared::class)->in('Feature');"],
    'the code pest() configures' => ["pest()->extend(Shared::class);"],
    'the code mutates() adds to what every run mutates' => ['mutates(Money::class);'],
    'a registration sent in' => ["it('adds', fn () => 1)->in(__DIR__);"],
    'a registration sent in, nullsafe' => ["test('adds', fn () => 1)?->in('Unit');"],
    'a write to $_ENV' => ["\$_ENV['SHARED'] = '5';"],
    'a write to the environment' => ["putenv('SHARED=5');"],
    'a write to $GLOBALS' => ["\$GLOBALS['shared'] = 5;"],
    'an include' => ["require __DIR__ . '/helpers.php';"],
    'a constant defined by call' => ["define('SHARED', 5);"],
    'a name that is not a call' => ['it;'],
    'an unknown statement' => ['Money::class;'],
    'a describe body that writes a global' => ["describe('money', function (): void { \$GLOBALS['shared'] = 5; it('adds', fn () => 1); });"],
    'a describe body that writes the environment' => ["describe('money', fn () => putenv('SHARED=5'));"],
    'a describe body that declares a function' => ["describe('money', function (): void { function shared(): int { return 1; } });"],
    'a hook sent in from a describe body' => ["describe('money', function (): void { beforeEach(fn () => 1)->in(__DIR__); });"],
    'a dataset closure that runs more than it gives' => ["dataset('amounts', function (): array { putenv('SHARED=5'); return [[1]]; });"],
    'a dataset closure that gives what runs code' => ["dataset('amounts', fn (): array => [[getenv('SHARED')]]);"],
    'a dataset with() takes as a closure that runs more' => ["it('adds', fn () => 1)->with(function (): array { \$GLOBALS['shared'] = 5; return [[1]]; });"],
    'an argument that calls' => ["it(sprintf('adds %d', 1), fn () => 1);"],
    'an argument that builds an object' => ["it('adds', fn () => 1)->with([new Money()]);"],
    'an argument that writes' => ["it('adds', fn () => 1)->skip(\$GLOBALS['shared'] = true);"],
    'a test handed as a variable' => ["it('adds', \$test);"],
    'a statement that runs past its registration' => ["it('adds', fn () => 1) || putenv('SHARED=5');"],
    'a write in a namespace\'s block' => ["namespace Tests\\Unit { putenv('SHARED=5'); }"],
    'a variable assigned what a call returns' => ["\$money = Money::of(5);"],
    'a variable assigned what a closure called at once returns' => ["\$money = (fn () => 1)();"],
    'a variable assigned another variable' => ["\$copy = \$other;"],
    'a variable assigned by reference' => ["\$alias = &\$other;"],
    'a variable operated on' => ["\$count .= 'more';"],
    'a superglobal assigned' => ["\$_ENV = ['SHARED' => '5'];"],
    'a superglobal\'s item assigned' => ["\$_SERVER['SHARED'] = '5';"],
    'a variable assigned in a describe body what a call returns' => ["describe('money', function (): void { \$money = Money::of(5); it('adds', fn () => 1); });"],
    'a variable assigned an anonymous class, which ends the statement' => ["\$clock = new class {};"],
    'a variable assigned a match, which ends the statement' => ["\$rate = match (PHP_OS_FAMILY) { 'Linux' => 1, default => 2 };"],
    'a variable not of its own handed to with()' => ["it('adds', fn (int \$a) => \$a)->with(\$rows);"],
    'a variable assigned what a call returns, handed to with()' => ["\$money = Money::of(5); it('adds', fn (Money \$a) => \$a)->with([\$money]);"],
    'a variable of its own called' => ["\$make = static fn (): array => [[1]]; it('adds', fn (int \$a) => \$a)->with(\$make());"],
    'variables that only assign each other' => ["\$a = \$b; \$b = \$a;"],
]);
