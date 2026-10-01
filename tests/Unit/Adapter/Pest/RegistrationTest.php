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
]);

it('holds a test file acting, and loaded in every narrowed run, whose top runs anything else', function (string $top) use ($inert): void {
    expect($inert($top))->toBeFalse();
})->with([
    'a hook pest() registers for a directory' => ["pest()->beforeEach(fn () => 1)->in(__DIR__);"],
    'a trait uses() adds to a directory' => ["uses(Shared::class)->in('Feature');"],
    'the code pest() configures' => ["pest()->extend(Shared::class);"],
    'the code mutates() adds to what every run mutates' => ['mutates(Money::class);'],
    'a registration sent in' => ["it('adds', fn () => 1)->in(__DIR__);", "test('adds', fn () => 1)?->in('Unit');"],
    'a write to $_ENV' => ["\$_ENV['SHARED'] = '5';"],
    'a write to the environment' => ["putenv('SHARED=5');"],
    'a write to $GLOBALS' => ["\$GLOBALS['shared'] = 5;"],
    'an include' => ["require __DIR__ . '/helpers.php';"],
    'a constant defined by call' => ["define('SHARED', 5);"],
    'a name that is not a call' => ['it;'],
    'an unknown statement' => ['Money::class;'],
]);
