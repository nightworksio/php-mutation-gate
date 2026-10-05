<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\ObjectLedger;
use NightWorksIO\MutationGate\Core\Proof\ObjectStore;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\FixedTokens;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;

/** An object API at https://objects.example, whose requests carry the token in a header of their own. */
function objectStoreAt(FixedTokens $tokens): ObjectStore
{
    return new readonly class ($tokens) implements ObjectStore {
        public function __construct(private FixedTokens $tokens)
        {
        }

        public function token(): Token|CannotJudge
        {
            return $this->tokens->token();
        }

        public function named(Scope $scope, string $key): string
        {
            return sprintf('objects://%s', $key);
        }

        public function reading(Scope $scope, string $path, Token $token): Request
        {
            return Request::get(sprintf('https://objects.example/%s', $path))->carrying($token);
        }

        public function writing(Scope $scope, string $path, Token $token, string $bytes): Request
        {
            return Request::put(sprintf('https://objects.example/%s', $path), $bytes)->carrying($token);
        }
    };
}

function objectLedgerProved(): Ledger
{
    $run = Run::of('github:1/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64)));

    return Ledger::empty()
        ->withProof(Proof::of(Digest::sha256Of('src/Money.php'), Path::of('src/Money.php'), Mutants::none(), $run))
        ->atBase($run->base());
}

it('writes a scope\'s ledger as one object under the prefix, each segment percent-encoded, and reads it back', function (): void {
    $cloud = new Cloud();
    $ledgers = ObjectLedger::under($cloud->exchange(), '/gate/');
    $store = objectStoreAt(FixedTokens::of('t'));
    $scope = Scope::branch('feature/añadir');

    expect($ledgers->write($store, $scope, objectLedgerProved()))
        ->toEqual(Written::to('objects://gate/refs/heads/feature/añadir/ledger.json.gz'))
        ->and($cloud->requests[0]['url'])->toBe('https://objects.example/gate/refs/heads/feature/a%C3%B1adir/ledger.json.gz')
        ->and($cloud->requests[0]['headers']['authorization'])->toBe('Bearer t')
        ->and(count(LedgerRead::ledger($ledgers->read($store, $scope))->proofs()))->toBe(1);
});

it('reads an empty ledger, and writes nothing, for a scope that is no ref', function (): void {
    $cloud = new Cloud();
    $ledgers = ObjectLedger::under($cloud->exchange(), 'gate');
    $tokens = FixedTokens::of('t');

    expect($ledgers->read(objectStoreAt($tokens), Scope::of('HEAD')))->toEqual(Ledger::empty())
        ->and($ledgers->write(objectStoreAt($tokens), Scope::of('HEAD'), objectLedgerProved()))->toBeInstanceOf(NotWritten::class)
        ->and($cloud->requests)->toBe([])
        ->and($tokens->asked)->toBe(0);
});

it('says why it reads and writes nothing where there is no token, and asks the store nothing', function (): void {
    $cloud = new Cloud();
    $ledgers = ObjectLedger::under($cloud->exchange(), 'gate');
    $store = objectStoreAt(FixedTokens::missing('sts.googleapis.com answered 400: invalid_grant'));

    expect($ledgers->read($store, Scope::branch('main')))->toEqual(Unreadable::because(
        UnreadReason::Refused,
        'objects://gate/refs/heads/main/ledger.json.gz',
        'no token: sts.googleapis.com answered 400: invalid_grant',
    ))
        ->and($ledgers->write($store, Scope::branch('main'), objectLedgerProved()))->toEqual(NotWritten::because(
            'objects://gate/refs/heads/main/ledger.json.gz could not be written: no token: sts.googleapis.com answered 400: invalid_grant',
        ))
        ->and($cloud->requests)->toBe([]);
});

it('reads an object that is no ledger as unreadable, and one past the limits too', function (): void {
    $url = 'https://objects.example/gate/refs/heads/main/ledger.json.gz';
    $named = 'objects://gate/refs/heads/main/ledger.json.gz';
    $store = objectStoreAt(FixedTokens::of('t'));
    $malformed = ObjectLedger::under(new Cloud()->holding($url, 'not a ledger')->exchange(), 'gate');
    $large = ObjectLedger::under(new Cloud()->holding($url, LedgerFile::encode(objectLedgerProved()))->exchange(), 'gate')
        ->within(LedgerLimits::of(1_000_000, 10, 60.0));

    expect(LedgerRead::unread($malformed->read($store, Scope::branch('main')))[0])->toBe(UnreadReason::Malformed)
        ->and(LedgerRead::unread($large->read($store, Scope::branch('main')))[0])->toBe(UnreadReason::TooLarge)
        ->and(LedgerRead::unread($malformed->read($store, Scope::branch('main')))[1])->toContain($named);
});

it('says why a write was refused, and keeps the ledger within the limits it writes by', function (): void {
    $url = 'https://objects.example/gate/refs/heads/main/ledger.json.gz';
    $refused = ObjectLedger::under(new Cloud()->answering($url, 403, 'denied')->exchange(), 'gate');
    $cloud = new Cloud();
    $trimmed = ObjectLedger::under($cloud->exchange(), 'gate')->within(LedgerLimits::of(1_000_000, 1, 60.0));

    expect($refused->write(objectStoreAt(FixedTokens::of('t')), Scope::branch('main'), objectLedgerProved()))
        ->toEqual(NotWritten::because('objects://gate/refs/heads/main/ledger.json.gz answered 403: denied'))
        ->and($trimmed->write(objectStoreAt(FixedTokens::of('t')), Scope::branch('main'), objectLedgerProved()))
        ->toEqual(Written::noting(
            'objects://gate/refs/heads/main/ledger.json.gz',
            'It keeps the newest 0 of 1 proofs, so a run can still read the ledger.',
        ));
});

it('keeps the coverage map as an object beside the scope\'s ledger, and reads it back byte for byte', function (): void {
    $cloud = new Cloud();
    $ledgers = ObjectLedger::under($cloud->exchange(), 'gate');
    $store = objectStoreAt(FixedTokens::of('t'));
    $bytes = Contents::of(Gzip::pack('{"format":1}'));

    expect($ledgers->keep($store, Scope::branch('main'), Companion::Coverage, $bytes))
        ->toEqual(Written::to('objects://gate/refs/heads/main/coverage.json.gz'))
        ->and($cloud->requests[0]['url'])->toBe('https://objects.example/gate/refs/heads/main/coverage.json.gz')
        ->and($ledgers->companion($store, Scope::branch('main'), Companion::Coverage))->toEqual($bytes)
        ->and($ledgers->companion($store, Scope::branch('other'), Companion::Coverage))
        ->toEqual(Missing::at(Path::of('gate/refs/heads/other/coverage.json.gz')));
});

it('reads no coverage map, and keeps none, for a scope that is no ref, and asks nothing', function (): void {
    $cloud = new Cloud();
    $tokens = FixedTokens::of('t');
    $ledgers = ObjectLedger::under($cloud->exchange(), 'gate');

    expect($ledgers->companion(objectStoreAt($tokens), Scope::of('HEAD'), Companion::Coverage))
        ->toEqual(Missing::at(Path::of('coverage.json.gz')))
        ->and($ledgers->keep(objectStoreAt($tokens), Scope::of('HEAD'), Companion::Coverage, Contents::of('x')))
        ->toBeInstanceOf(NotWritten::class)
        ->and($cloud->requests)->toBe([])
        ->and($tokens->asked)->toBe(0);
});

it('says why it reads and keeps no coverage map where there is no token, or the map is past its limit', function (): void {
    $url = 'https://objects.example/gate/refs/heads/main/coverage.json.gz';
    $named = 'objects://gate/refs/heads/main/coverage.json.gz';
    $none = objectStoreAt(FixedTokens::missing('invalid_grant'));
    $ledgers = ObjectLedger::under(new Cloud()->exchange(), 'gate');
    $large = ObjectLedger::under(new Cloud()->holding($url, str_repeat('x', MapLimits::standard()->packed() + 1))->exchange(), 'gate');

    expect($ledgers->companion($none, Scope::branch('main'), Companion::Coverage))
        ->toEqual(CannotJudge::because(sprintf('The kept coverage map at %s could not be read: no token: invalid_grant.', $named)))
        ->and($ledgers->keep($none, Scope::branch('main'), Companion::Coverage, Contents::of('x')))
        ->toEqual(NotWritten::because(sprintf('%s could not be written: no token: invalid_grant', $named)))
        ->and($large->companion(objectStoreAt(FixedTokens::of('t')), Scope::branch('main'), Companion::Coverage))
        ->toEqual(CannotJudge::because(sprintf('The kept coverage map at %s could not be read: it is larger than 1500000 bytes.', $named)));
});
