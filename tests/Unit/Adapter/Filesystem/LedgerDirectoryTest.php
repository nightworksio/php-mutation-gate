<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\LedgerDirectory;
use NightWorksIO\MutationGate\Core\Config\Definition\Builtins;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Doctor\KeptLedger;
use NightWorksIO\MutationGate\Core\Doctor\KeptLedgers;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$proved = static fn(): Ledger => Ledger::empty()->withProof(Proof::of(
    Digest::sha256Of('src/Money.php'),
    Path::of('src/Money.php'),
    Mutants::none(),
    Run::of('github:1/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64))),
))->atBase(Digest::of(str_repeat('b', 64)));

it('reads an empty ledger for a scope nothing wrote', function (): void {
    expect(LedgerDirectory::at(Scratch::directory())->read(Scope::branch('main')))->toEqual(Ledger::empty());
});

it('writes a scope\'s ledger to its own file under the directory, and says where', function () use ($proved): void {
    $root = Scratch::directory();
    $written = LedgerDirectory::at($root)->write(Scope::pullRequest(12), $proved());

    expect($written)->toEqual(Written::to(sprintf('%s/refs/pull/12/ledger.json.gz', $root)))
        ->and(file_get_contents(sprintf('%s/refs/pull/12/ledger.json.gz', $root)))->toBe(LedgerFile::encode($proved()));
});

it('reads back the ledger it wrote to a scope', function () use ($proved): void {
    $store = LedgerDirectory::at(Scratch::directory());
    $store->write(Scope::branch('main'), $proved());

    expect(LedgerFile::encode(LedgerRead::ledger($store->read(Scope::branch('main')))))->toBe(LedgerFile::encode($proved()))
        ->and($store->read(Scope::branch('release')))->toEqual(Ledger::empty());
});

it('says a ledger file holds no ledger this gate reads, and where it is', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'refs/heads/main/ledger.json.gz', 'not JSON at all');

    expect(LedgerRead::unread(LedgerDirectory::at($root)->read(Scope::branch('main'))))->toBe([
        UnreadReason::Malformed,
        sprintf(
            'The ledger is unreadable from %s/refs/heads/main/ledger.json.gz: The ledger is not a whole gzip stream. The run judges without it.',
            $root,
        ),
    ]);
});

it('says a ledger file could not be read, and where it is', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'refs/heads/main/ledger.json.gz/placeholder', '');

    [$reason, $why] = LedgerRead::unread(LedgerDirectory::at($root)->read(Scope::branch('main')));

    expect($reason)->toBe(UnreadReason::Refused)
        ->and($why)->toStartWith(sprintf('The ledger is unreadable from %s/refs/heads/main/ledger.json.gz: ', $root));
});

it('reads nothing for a scope that is not a ref, though its path leads to a ledger', function () use ($proved): void {
    $root = Scratch::directory();
    $store = LedgerDirectory::at($root);
    $store->write(Scope::branch('main'), $proved());

    expect($store->read(Scope::of('refs/heads/main/../main')))->toEqual(Ledger::empty());
});

it('writes nothing for a scope that is not a ref, and says why', function () use ($proved): void {
    $root = Scratch::directory();

    expect(LedgerDirectory::at($root)->write(Scope::of('refs/heads/../../escaped'), $proved()))
        ->toEqual(NotWritten::because(
            '"refs/heads/../../escaped" is not a scope. A scope is refs/heads/<branch> or refs/pull/<number>.',
        ))
        ->and(glob(sprintf('%s/*', $root)))->toBe([]);
});

it('says why a ledger it cannot write was not written', function () use ($proved): void {
    $root = Scratch::directory();
    mkdir(sprintf('%s/refs/heads/main/ledger.json.gz', $root), recursive: true);

    expect(LedgerDirectory::at($root)->write(Scope::branch('main'), $proved()))
        ->toEqual(NotWritten::because(sprintf('%s/refs/heads/main/ledger.json.gz could not be written.', $root)));
});

it('keeps its ledgers under .mutation-gate/ledger unless the options name another directory', function (): void {
    expect(Workspace::ledger()->value())->toBe('.mutation-gate/ledger')
        ->and(LedgerDirectory::fromOptions(Configs::builtin(Builtins::stores(ProjectRoot::origin()), 'directory')))
        ->toEqual(LedgerDirectory::at('.mutation-gate/ledger'))
        ->and(LedgerDirectory::fromOptions(Configs::options('{"path": "build/ledgers"}')))
        ->toEqual(LedgerDirectory::at('build/ledgers'));
});

it('refuses a directory that is not written as text, or not given', function (): void {
    expect(LedgerDirectory::fromOptions(Configs::options('{"path": 7}')))
        ->toEqual(Invalid::because(Problem::at('path', 'expected text, got 7')))
        ->and(LedgerDirectory::fromOptions(Options::none()))
        ->toEqual(Invalid::because(Problem::at('path', 'expected the directory the ledgers are kept in, got nothing')));
});

it('lists every ledger kept under the directory, in the order of its path, with its size compressed', function () use ($proved): void {
    $root = Scratch::directory();
    $store = LedgerDirectory::at($root);
    $store->write(Scope::pullRequest(12), $proved());
    $store->write(Scope::branch('feature/money'), Ledger::empty());
    Scratch::write($root, 'refs/heads/notes.txt', 'not a ledger');
    $size = static fn(string $scope): int => (int) filesize(sprintf('%s/%s/ledger.json.gz', $root, $scope));

    expect($store->kept())->toEqual(KeptLedgers::of(
        KeptLedger::of(Path::of(sprintf('%s/refs/heads/feature/money/ledger.json.gz', $root)), $size('refs/heads/feature/money')),
        KeptLedger::of(Path::of(sprintf('%s/refs/pull/12/ledger.json.gz', $root)), $size('refs/pull/12')),
    ))->and(LedgerDirectory::at(sprintf('%s/nowhere', $root))->kept())->toEqual(KeptLedgers::of());
});

it('keeps a directory from the project inside it, and writes no ledger that leads out of it', function () use ($proved): void {
    $root = Scratch::directory();
    Scratch::write($root, 'project/composer.json', '{}');
    $directory = (string) getcwd();
    chdir(sprintf('%s/project', $root));
    $inside = LedgerDirectory::at('build/ledger')->write(Scope::branch('main'), $proved());
    $up = LedgerDirectory::at('../ledger')->write(Scope::branch('main'), $proved());
    chdir($directory);

    expect($inside)->toEqual(Written::to('./build/ledger/refs/heads/main/ledger.json.gz'))
        ->and($up)->toEqual(NotWritten::because(
            '../ledger/refs/heads/main/ledger.json.gz leads out of ., so the gate does not read or write it.',
        ))
        ->and(glob(sprintf('%s/ledger', $root)))->toBe([]);
});

it('writes a ledger within its limits, dropping the oldest proofs, and says so', function () use ($proved): void {
    $root = Scratch::directory();
    $newer = $proved()->withProof(Proof::of(
        Digest::sha256Of('src/Tax.php'),
        Path::of('src/Tax.php'),
        Mutants::none(),
        Run::of('github:1/2', Instant::at(new DateTimeImmutable('2026-09-30T20:48:17Z')), Digest::of(str_repeat('b', 64))),
    ));
    $limits = LedgerLimits::of(strlen(LedgerFile::encode($newer)) - 1, 38_000_000, 60.0);
    $store = LedgerDirectory::at($root)->within($limits);

    $written = $store->write(Scope::branch('main'), $newer);
    $read = LedgerRead::ledger($store->read(Scope::branch('main')));

    expect($written)->toEqual(Written::noting(
        sprintf('%s/refs/heads/main/ledger.json.gz', $root),
        'It keeps the newest 1 of 2 proofs, so a run can still read the ledger.',
    ))
        ->and($read->proofs()->has(Digest::sha256Of('src/Tax.php')))->toBeTrue()
        ->and($read->proofs())->toHaveCount(1);
});
