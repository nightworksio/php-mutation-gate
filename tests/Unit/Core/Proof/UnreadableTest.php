<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

const UNREAD_FROM = 'https://ledgers.example.com/refs/heads/main/ledger.json.gz';

it('says where a ledger was not read and why, in one line whatever the detail ends with', function (string $detail): void {
    $unread = Unreadable::because(UnreadReason::Refused, UNREAD_FROM, $detail);

    expect($unread->why())->toBe(sprintf(
        'The ledger is unreadable from %s: HTTP 503. The run judges without it.',
        UNREAD_FROM,
    ))
        ->and($unread->reason())->toBe(UnreadReason::Refused)
        ->and($unread->ledger())->toEqual(Ledger::empty())
        ->and($unread->said())->toEqual(Warnings::of(Warning::that($unread->why())));
})->with([
    'a detail with no stop' => ['HTTP 503'],
    'a detail with a stop' => ['HTTP 503.'],
]);

it('reads a file that holds no ledger as malformed, and one past the limit as too large', function (): void {
    $malformed = Unreadable::notRead(UNREAD_FROM, CannotJudge::because('The ledger is not a whole gzip stream.'));
    $large = Unreadable::notRead(UNREAD_FROM, TooLarge::because('it is larger than 25 bytes'));

    expect([$malformed->reason(), $large->reason()])->toBe([UnreadReason::Malformed, UnreadReason::TooLarge])
        ->and($large->why())->toBe(sprintf(
            'The ledger is unreadable from %s: it is larger than 25 bytes. The run judges without it.',
            UNREAD_FROM,
        ));
});

it('keeps what else the store read beside it, and what else it could not read, in the order they came', function (): void {
    $beside = Ledger::empty()->atBase(Digest::of(str_repeat('b', 64)));
    $local = Unreadable::because(UnreadReason::Malformed, '/tmp/ledger.json.gz', 'not a ledger');
    $shared = Unreadable::because(UnreadReason::TimedOut, UNREAD_FROM, 'no answer came in time')
        ->besides($beside)
        ->also($local);

    expect($shared->ledger())->toBe($beside)
        ->and($shared->reason())->toBe(UnreadReason::TimedOut)
        ->and($shared->said())->toEqual(Warnings::of(Warning::that($shared->why()), Warning::that($local->why())))
        ->and($shared->besides(Ledger::empty())->said())->toHaveCount(2);
});
