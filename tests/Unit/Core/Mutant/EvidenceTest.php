<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\OrderDigest;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\TestId;

$id = static fn(string $mutator): MutantId => MutantId::hash(Path::of('src/Money.php'), $mutator, "@@ @@\n-a\n+b", 0);

it('digests a run\'s order test by test, the same however it is built', function (): void {
    $built = OrderDigest::start()->with(TestId::of('MoneyTest::adds'))->with(TestId::of('MoneyTest::drains'));

    expect($built->value())->toBe(hash('sha256', "MoneyTest::adds\nMoneyTest::drains\n"))
        ->and(OrderDigest::of(TestId::of('MoneyTest::adds'), TestId::of('MoneyTest::drains'))->value())->toBe($built->value())
        ->and(OrderDigest::start()->value())->toBe(hash('sha256', ''))
        ->and(OrderDigest::of(TestId::of('MoneyTest::drains'), TestId::of('MoneyTest::adds'))->value())->not->toBe($built->value());
});

it('keys a prefix by the test files its run loaded, in any order, and the digest of its order up to the last killer', function (): void {
    $order = OrderDigest::of(TestId::of('MoneyTest::adds'))->value();
    $key = Prefix::keyOf(Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/TaxTest.php')), $order);

    expect($key)->toBe(mb_substr(hash('sha256', sprintf("tests/MoneyTest.php\ntests/TaxTest.php\n\n%s", $order)), 0, 12))
        ->and(Prefix::keyOf(Paths::of(Path::of('tests/TaxTest.php'), Path::of('tests/MoneyTest.php')), $order))->toBe($key)
        ->and(Prefix::keyOf(Paths::none(), $order))->toBe(mb_substr(hash('sha256', sprintf("\n%s", $order)), 0, 12))
        ->and(Prefix::keyOf(Paths::none(), OrderDigest::of(TestId::of('MoneyTest::drains'))->value()))->not->toBe(Prefix::keyOf(Paths::none(), $order));
});

it('holds where a run\'s first failing test stood, from one, and its key where the runner gave one', function (): void {
    $keyed = Prefix::keyedAt(3, '0123456789ab');

    expect($keyed->position())->toBe(3)
        ->and($keyed->key())->toBe('0123456789ab')
        ->and(Prefix::at(1)->key())->toEqual(NotGiven::value())
        ->and(Prefix::read(1, NotGiven::value()))->toEqual(Prefix::at(1))
        ->and(Prefix::read(2, '0123456789ab'))->toEqual(Prefix::keyedAt(2, '0123456789ab'));
});

it('refuses a position below one and a key that is not twelve hex digits', function (int $position, string|NotGiven $key): void {
    expect(Prefix::read($position, $key))->toBeInstanceOf(NotGiven::class);
})->with([
    'nought' => [0, fn(): NotGiven => NotGiven::value()],
    'below nought' => [-1, fn(): NotGiven => NotGiven::value()],
    'a short key' => [1, '0123456789a'],
    'a long key' => [1, '0123456789abc'],
    'capitals' => [1, '0123456789AB'],
    'not hex' => [1, '0123456789ag'],
]);

it('keeps how a process ended: its code, whether a signal ended it, and the end of what it printed', function (): void {
    $ended = Ended::of(137, signalled: true, printed: "boom\n");

    expect($ended->code())->toBe(137)
        ->and($ended->signalled())->toBeTrue()
        ->and($ended->tail())->toBe("boom\n")
        ->and($ended->printed())->toBe("boom\n")
        ->and(Ended::of(NotGiven::value(), NotGiven::value(), '')->code())->toEqual(NotGiven::value())
        ->and(Ended::of(NotGiven::value(), NotGiven::value(), '')->signalled())->toEqual(NotGiven::value());
});

it('tails what a process printed at 2 KiB, on a character\'s edge, every control character but tabs and line ends dropped', function (): void {
    $long = sprintf("%sé\x1b[31mred\x07\tend\r\n", str_repeat('a', 5000));
    $tailed = Ended::of(1, signalled: false, printed: $long)->tail();
    $tail = is_string($tailed) ? $tailed : '';

    expect(strlen($tail))->toBeLessThanOrEqual(2048)
        ->and($tail)->toEndWith("é[31mred\tend\r\n")
        ->and(mb_check_encoding($tail, 'UTF-8'))->toBeTrue()
        ->and(str_contains($tail, "\x1b"))->toBeFalse()
        ->and(Ended::of(1, signalled: false, printed: str_repeat('é', 1500))->tail())->toBe(str_repeat('é', 1024))
        ->and(Ended::of(1, signalled: false, printed: sprintf('x%s', str_repeat('€', 683)))->tail())->toBe(str_repeat('€', 682))
        ->and(Ended::of(1, signalled: false, printed: sprintf('😀%s', str_repeat('a', 2047)))->tail())->toBe(str_repeat('a', 2047))
        ->and(Ended::of(1, signalled: false, printed: sprintf('b%s', str_repeat('a', 2048)))->tail())->toBe(str_repeat('a', 2048))
        ->and(Ended::of(1, signalled: false, printed: $tail)->tail())->toBe($tail);
});

it('keeps four times its tail of what a process printed, and whether it cut any, so a secret is screened before the cut', function (): void {
    $printed = sprintf('%s%s', str_repeat('é', 5000), 'end');
    $ended = Ended::of(1, signalled: false, printed: $printed);
    $kept = $ended->printed();

    expect(is_string($kept) ? strlen($kept) : 0)->toBeLessThanOrEqual(8192)
        ->and(is_string($kept) ? strlen($kept) : 0)->toBeGreaterThan(8186)
        ->and($kept)->toEndWith('éend')
        ->and(is_string($kept) && mb_check_encoding($kept, 'UTF-8'))->toBeTrue()
        ->and($ended->wasCut())->toBeTrue()
        ->and(Ended::of(1, signalled: false, printed: str_repeat('a', 8192))->wasCut())->toBeFalse();
});

it('keeps how a process ended with nothing of what it printed, where that is not given', function (): void {
    $ended = Ended::unprinted(2, signalled: false);

    expect([$ended->code(), $ended->signalled()])->toBe([2, false])
        ->and($ended->printed())->toBeInstanceOf(NotGiven::class)
        ->and($ended->tail())->toBeInstanceOf(NotGiven::class)
        ->and($ended->wasCut())->toBeFalse();
});

it('says whether PHP recorded a fatal error in the process, where the runner told, and keeps it when screened', function (): void {
    $ended = Ended::of(255, signalled: false, printed: "PHP Fatal error:  boom\n");
    $fatal = $ended->withFatal(fatal: true);

    expect($ended->fatal())->toBeInstanceOf(NotGiven::class)
        ->and($fatal->fatal())->toBeTrue()
        ->and($ended->withFatal(fatal: false)->fatal())->toBeFalse()
        ->and([$fatal->code(), $fatal->signalled(), $fatal->tail()])->toBe([255, false, "PHP Fatal error:  boom\n"])
        ->and(Ended::screened($fatal, NotGiven::value())->fatal())->toBeTrue()
        ->and(Ended::screened($fatal, NotGiven::value())->tail())->toBeInstanceOf(NotGiven::class)
        ->and(Ended::screened($fatal, 'kept')->tail())->toBe('kept')
        ->and(Ended::unprinted(1, signalled: false)->fatal())->toBeInstanceOf(NotGiven::class);
});

it('keeps a run\'s ending: its code, a signal where its code is known, what it printed, and PHP\'s record of a fatal error', function (Ran $ran, array $expected): void {
    $ended = Ended::ofRun($ran);

    expect([$ended->code(), $ended->signalled(), $ended->fatal(), $ended->tail()])->toEqual($expected);
})->with([
    'exited' => [fn(): Ran => Ran::exited(1, "fails\n"), [1, false, false, "fails\n"]],
    'a fatal error' => [fn(): Ran => Ran::exited(255, "PHP Fatal error:  boom\n"), [255, false, true, "PHP Fatal error:  boom\n"]],
    'signalled' => [fn(): Ran => Ran::signalled(9, ''), [137, true, false, '']],
    'no code' => [fn(): Ran => Ran::exited(NotGiven::value(), 'gone'), fn(): array => [NotGiven::value(), NotGiven::value(), false, 'gone']],
]);

it('holds a kill\'s evidence: a prefix, how its process ended, either or neither', function (): void {
    $prefix = Prefix::at(2);
    $ended = Ended::of(255, signalled: false, printed: 'Fatal error');

    expect(Evidence::none()->isEmpty())->toBeTrue()
        ->and(Evidence::none()->withPrefix($prefix)->prefix())->toBe($prefix)
        ->and(Evidence::none()->withPrefix($prefix)->ended())->toEqual(NotGiven::value())
        ->and(Evidence::none()->withEnded($ended)->ended())->toBe($ended)
        ->and(Evidence::none()->withEnded($ended)->isEmpty())->toBeFalse()
        ->and(Evidence::none()->withPrefix($prefix)->isEmpty())->toBeFalse()
        ->and(Evidence::none()->withPrefix($prefix)->withEnded($ended)->prefix())->toBe($prefix)
        ->and(Evidence::none()->withEnded($ended)->withPrefix($prefix)->ended())->toBe($ended);
});

it('holds each mutant\'s evidence by its id, the last given winning, and none for a mutant it was given none of', function () use ($id): void {
    $first = Evidence::none()->withPrefix(Prefix::at(1));
    $second = Evidence::none()->withPrefix(Prefix::at(2));
    $evidences = Evidences::none()->with($id('a'), $first)->with($id('b'), $second);

    expect($evidences->of($id('a')))->toBe($first)
        ->and($evidences->of($id('c')))->toEqual(Evidence::none())
        ->and($evidences->with($id('a'), $second)->of($id('a')))->toBe($second)
        ->and($evidences->with($id('a'), Evidence::none())->of($id('a')))->toEqual(Evidence::none())
        ->and($evidences)->toHaveCount(2)
        ->and(Evidences::none()->with($id('a'), Evidence::none()))->toHaveCount(0)
        ->and($evidences->and(Evidences::none()->with($id('c'), $first))->of($id('c')))->toBe($first)
        ->and($evidences->and(Evidences::none()->with($id('a'), $second))->of($id('a')))->toBe($second);
});

it('keeps the evidence of a mutant whose id reads as a number under that id, joined or not', function (): void {
    $numeric = MutantId::parse('123456789012');
    $other = MutantId::parse('023456789012');
    $evidence = Evidence::none()->withPrefix(Prefix::at(5));

    expect($numeric)->toBeInstanceOf(MutantId::class)
        ->and($numeric instanceof MutantId && $other instanceof MutantId ? Evidences::none()->with($numeric, $evidence)
            ->and(Evidences::none()->with($other, $evidence))->of($numeric) : null)->toBe($evidence);
});
