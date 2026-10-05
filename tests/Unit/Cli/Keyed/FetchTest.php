<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Keyed\Fetch;
use NightWorksIO\MutationGate\Cli\Keyed\Installation;
use NightWorksIO\MutationGate\Cli\Keyed\LocatedStore;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** The default branch's ledger in the store. */
function fetchedLedger(): Ledger
{
    return Ledger::empty()->atBase(Digest::sha256Of('main'));
}

/** Where fetch's environment says the store is, an S3 bucket, and the key it reads with. */
const FETCH_STORE = [
    'MUTATION_GATE_STORE' => 's3',
    'MUTATION_GATE_STORE_BUCKET' => 'proofs',
    'MUTATION_GATE_DEFAULT_BRANCH' => 'main',
    'AWS_ACCESS_KEY_ID' => 'id',
    'AWS_SECRET_ACCESS_KEY' => 'secret',
];

/**
 * What fetch says, on its output and its errors, and its exit code, run in this project with this store.
 *
 * @param  list<string>          $argv
 * @param  array<string, string> $environment
 * @return array{string, string, int}
 */
function fetchRun(string $project, array $argv, array $environment, ProofStore $store, string $vendor = '', bool $throughComposer = false): array
{
    $located = new LocatedStore(Variables::of($environment), new Extensions(Origin::of('acme/gate'))->withProofStore(Name::of('s3'), static fn(): ProofStore => $store));
    $output = new BufferedOutput();
    $errors = new BufferedOutput();
    $installation = Installation::of($project, $vendor === '' ? Scratch::directory() : $vendor, $throughComposer);
    $code = new Fetch($installation, Variables::of($environment), $located)->run(new ArgvInput($argv), $output, $errors);

    return [$output->fetch(), $errors->fetch(), $code];
}

/** The ledger a directory keeps for a scope; nothing where it keeps none. */
function fetchedIn(string $directory, string $ref): Ledger|CannotJudge|TooLarge|string
{
    $file = sprintf('%s/%s/ledger.json.gz', $directory, $ref);

    return is_file($file) ? LedgerFile::read((string) file_get_contents($file), LedgerLimits::standard()) : 'nothing';
}

it('is asked for by its name alone', function (): void {
    expect(Fetch::isAsked(new ArgvInput(['mutation-gate', 'fetch', '--to=x'])))->toBeTrue()
        ->and(Fetch::isAsked(new ArgvInput(['mutation-gate', 'deliver'])))->toBeFalse();
});

it('reads the default branch\'s ledger alone, writes it where the directory store reads it, and writes nothing to the store', function (): void {
    $project = Scratch::directory();
    $store = new ProofStoreFake()->keeping(Scope::branch('main'), fetchedLedger())->keeping(Scope::pullRequest(7), Ledger::empty()->atBase(Digest::sha256Of('pr')));
    chdir($project);
    [$output, $errors, $code] = fetchRun($project, ['mutation-gate', 'fetch'], FETCH_STORE, $store);

    expect([$errors, $code])->toBe(['', 0])
        ->and($output)->toStartWith('Wrote ./.mutation-gate/ledger/refs/heads/main/ledger.json.gz.')
        ->and($store->asked())->toBe(['read refs/heads/main'])
        ->and(fetchedIn(sprintf('%s/.mutation-gate/ledger', $project), 'refs/heads/main'))->toEqual(fetchedLedger())
        ->and(fetchedIn(sprintf('%s/.mutation-gate/ledger', $project), 'refs/pull/7'))->toBe('nothing');
});

it('writes the ledger into the directory --to names', function (): void {
    $project = Scratch::directory();
    $store = new ProofStoreFake()->keeping(Scope::branch('main'), fetchedLedger());

    expect(fetchRun($project, ['mutation-gate', 'fetch', sprintf('--to=%s/proofs', $project)], FETCH_STORE, $store)[2])->toBe(0)
        ->and(fetchedIn(sprintf('%s/proofs', $project), 'refs/heads/main'))->toEqual(fetchedLedger());
});

it('takes the default branch from the event payload over the variable', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'event.json', '{"repository": {"default_branch": "trunk"}}');
    $store = new ProofStoreFake();
    fetchRun($project, ['mutation-gate', 'fetch', sprintf('--to=%s/proofs', $project)], [...FETCH_STORE, 'GITHUB_EVENT_PATH' => sprintf('%s/event.json', $project)], $store);

    expect($store->asked())->toBe(['read refs/heads/trunk']);
});

it('passes, saying why, where the store cannot be read', function (): void {
    $project = Scratch::directory();
    $store = ProofStoreFake::unreadable(Unreadable::because(UnreadReason::Unreachable, 's3://proofs/mutation-gate/refs/heads/main/ledger.json.gz', 'the connection failed'));

    expect(fetchRun($project, ['mutation-gate', 'fetch', sprintf('--to=%s/proofs', $project)], FETCH_STORE, $store))
        ->toBe(["The ledger is unreadable from s3://proofs/mutation-gate/refs/heads/main/ledger.json.gz: the connection failed. The run judges without it.\n", '', 0])
        ->and(fetchedIn(sprintf('%s/proofs', $project), 'refs/heads/main'))->toBe('nothing');
});

it('passes, saying why, and reads nothing, where the job holds no key to read the store with', function (): void {
    $project = Scratch::directory();
    $store = new ProofStoreFake()->keeping(Scope::branch('main'), fetchedLedger());
    $keyless = array_diff_key(FETCH_STORE, ['AWS_ACCESS_KEY_ID' => true, 'AWS_SECRET_ACCESS_KEY' => true]);

    expect(fetchRun($project, ['mutation-gate', 'fetch', sprintf('--to=%s/proofs', $project)], $keyless, $store))
        ->toBe(["The default branch's ledger is not fetched: MUTATION_GATE_STORE names s3, whose credentials this job does not hold.\n", '', 0])
        ->and($store->asked())->toBe([])
        ->and(fetchedIn(sprintf('%s/proofs', $project), 'refs/heads/main'))->toBe('nothing');
});

it('passes, reading nothing, where no store is named, whether or not a default branch is', function (string $defaultBranch): void {
    $project = Scratch::directory();
    $store = new ProofStoreFake()->keeping(Scope::branch('main'), fetchedLedger());
    $unnamed = array_filter(
        [...array_diff_key(FETCH_STORE, ['MUTATION_GATE_STORE' => true]), 'MUTATION_GATE_DEFAULT_BRANCH' => $defaultBranch],
        static fn(string $value): bool => $value !== '',
    );

    expect(fetchRun($project, ['mutation-gate', 'fetch', sprintf('--to=%s/proofs', $project)], $unnamed, $store))
        ->toBe(["MUTATION_GATE_STORE names no store, so fetch reads no ledger.\n", '', 0])
        ->and($store->asked())->toBe([])
        ->and(fetchedIn(sprintf('%s/proofs', $project), 'refs/heads/main'))->toBe('nothing');
})->with(['main', '']);

it('cannot judge where nothing names the default branch, the store is not located, or the ledger cannot be written', function (string $defaultBranch, string $store, string $to, string $why): void {
    $project = Scratch::directory();
    Scratch::write($project, 'taken/refs/heads/main/ledger.json.gz/kept', '');
    $environment = array_filter(
        [...FETCH_STORE, 'MUTATION_GATE_DEFAULT_BRANCH' => $defaultBranch, 'MUTATION_GATE_STORE' => $store],
        static fn(string $value): bool => $value !== '',
    );

    expect(fetchRun($project, ['mutation-gate', 'fetch', sprintf('--to=%s/%s', $project, $to)], $environment, new ProofStoreFake()->keeping(Scope::branch('main'), fetchedLedger())))
        ->toBe(['', sprintf($why, $project), 2]);
})->with([
    'no default branch' => ['', 's3', 'proofs', "Neither the event payload nor MUTATION_GATE_DEFAULT_BRANCH names the default branch.\n"],
    'a default branch that is none' => ['../main', 's3', 'proofs', "\"refs/heads/../main\" is not a scope. A scope is refs/heads/<branch> or refs/pull/<number>.\n"],
    'a store that is none' => ['main', 'S3', 'proofs', "MUTATION_GATE_STORE names no built-in store that needs credentials: s3, gcs or azure.\n"],
    'a directory in the way' => ['main', 's3', 'taken', "%s/taken/refs/heads/main/ledger.json.gz could not be written.\n"],
]);

it('refuses to start through Composer\'s proxy, or from the project\'s own vendor, and a command line it does not take', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'vendor/autoload.php', '<?php');
    $refused = "fetch runs from the gate's own installation, never the project's, so it loads none of the project's code.\n";
    $store = new ProofStoreFake();

    expect(fetchRun($project, ['mutation-gate', 'fetch'], FETCH_STORE, $store, throughComposer: true))->toBe(['', $refused, 2])
        ->and(fetchRun($project, ['mutation-gate', 'fetch'], FETCH_STORE, $store, vendor: sprintf('%s/vendor', $project)))->toBe(['', $refused, 2])
        ->and(fetchRun($project, ['mutation-gate', 'fetch', '--from=x'], FETCH_STORE, $store)[1])->toStartWith('fetch refuses its command line: ')
        ->and($store->asked())->toBe([]);
});

it('starts in this process with the gate\'s own adapters, and refuses before anything else', function (): void {
    $output = new BufferedOutput();
    $errors = new BufferedOutput();
    $code = Fetch::online(Installation::of(Scratch::directory(), Scratch::directory(), throughComposer: true))
        ->run(new ArgvInput(['mutation-gate', 'fetch']), $output, $errors);

    expect([$output->fetch(), $errors->fetch(), $code])
        ->toBe(['', "fetch runs from the gate's own installation, never the project's, so it loads none of the project's code.\n", 2]);
});
