<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\DeliveredLedger;
use NightWorksIO\MutationGate\Adapter\Filesystem\DeliveredReport;
use NightWorksIO\MutationGate\Adapter\Filesystem\DeliveryDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Delivery\AlertPost;
use NightWorksIO\MutationGate\Core\Delivery\Deferring;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Delivery\DeliveryFile;
use NightWorksIO\MutationGate\Core\Delivery\KeptPost;
use NightWorksIO\MutationGate\Core\Delivery\LedgerPost;
use NightWorksIO\MutationGate\Core\Delivery\Stage;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

/** The delivery a project's stage holds, or why it cannot be read. */
function deliveredIn(string $project, Stage $stage): Delivery|CannotJudge|string
{
    $file = sprintf('%s/%s/delivery.json', $project, $stage->directory()->value());

    return is_file($file) ? DeliveryFile::decode((string) file_get_contents($file)) : 'nothing';
}

/** A ledger with something in it. */
function deliveredLedger(): Ledger
{
    return Ledger::empty()->atBase(Digest::sha256Of('base'));
}

it('keeps each stage in its own directory under .mutation-gate/delivery', function (): void {
    expect(array_map(static fn(Stage $stage): string => $stage->directory()->value(), Stage::cases()))
        ->toBe(['.mutation-gate/delivery/planned', '.mutation-gate/delivery/verdict']);
});

it('begins empty, with no ledger beside it, whatever an earlier run left', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, '.mutation-gate/delivery/verdict/delivery.json', '{"format": 1, "comment": "stale"}');
    Scratch::write($project, '.mutation-gate/delivery/verdict/ledger.json.gz', 'stale');
    Scratch::write($project, '.mutation-gate/delivery/verdict/coverage.json.gz', 'stale');

    $begun = DeliveryDirectory::of(Directory::at($project), Stage::Verdict)->begun();

    expect($begun)->toEqual(Written::to(sprintf('%s/.mutation-gate/delivery/verdict/delivery.json', $project)))
        ->and(deliveredIn($project, Stage::Verdict))->toEqual(Delivery::none())
        ->and(is_file(sprintf('%s/.mutation-gate/delivery/verdict/ledger.json.gz', $project)))->toBeFalse()
        ->and(is_file(sprintf('%s/.mutation-gate/delivery/verdict/coverage.json.gz', $project)))->toBeFalse()
        ->and(deliveredIn($project, Stage::Planned))->toBe('nothing');
});

it('cannot begin where the ledger an earlier run left cannot be removed, and leaves its delivery as it was', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, '.mutation-gate/delivery/verdict/delivery.json', '{"format": 1, "comment": "stale"}');
    Scratch::write($project, '.mutation-gate/delivery/verdict/ledger.json.gz', 'stale');
    $stage = sprintf('%s/.mutation-gate/delivery/verdict', $project);
    chmod($stage, 0o555);
    $begun = DeliveryDirectory::of(Directory::at($project), Stage::Verdict)->begun();
    chmod($stage, 0o755);

    expect($begun)->toEqual(CannotJudge::because(sprintf('%s/ledger.json.gz could not be removed.', $stage)))
        ->and(deliveredIn($project, Stage::Verdict))->toEqual(Delivery::none()->withComment('stale'));
});

it('adds to what it holds, and to nothing where it holds nothing yet', function (): void {
    $project = Scratch::directory();
    $delivery = DeliveryDirectory::of(Directory::at($project), Stage::Planned);
    $delivery->adding(static fn(Delivery $held): Delivery => $held->withComment('one'));
    $delivery->adding(static fn(Delivery $held): Delivery => $held->withAlert(AlertPost::of(BuiltinReporter::Slack, '{}')));

    expect(deliveredIn($project, Stage::Planned))
        ->toEqual(Delivery::none()->withComment('one')->withAlert(AlertPost::of(BuiltinReporter::Slack, '{}')));
});

it('adds nothing to a delivery it cannot read, and says why', function (string $held, string $why): void {
    $project = Scratch::directory();
    Scratch::write($project, $held === '' ? '.mutation-gate/delivery/verdict/delivery.json/kept' : '.mutation-gate/delivery/verdict/delivery.json', $held);

    $added = DeliveryDirectory::of(Directory::at($project), Stage::Verdict)
        ->adding(static fn(Delivery $delivery): Delivery => $delivery->withComment('x'));

    expect($added)->toEqual(CannotJudge::because(sprintf($why, $project)));
})->with([
    'not a delivery' => ['{"format": 2}', 'The delivery cannot be read, so deliver sends nothing: delivery.format is not format 1.'],
    'a directory' => ['', '%s/.mutation-gate/delivery/verdict/delivery.json could not be read.'],
    'past the limit' => [
        str_repeat(' ', LedgerLimits::standard()->packed() + 1),
        sprintf('%%s/.mutation-gate/delivery/verdict/delivery.json is past %d bytes, so it is not read.', LedgerLimits::standard()->packed()),
    ],
]);

it('writes the ledger beside the delivery, within the limits a run reads, and names its scope in it', function (): void {
    $project = Scratch::directory();
    $written = DeliveryDirectory::of(Directory::at($project), Stage::Verdict)->ledger(Scope::branch('main'), deliveredLedger());
    $file = sprintf('%s/.mutation-gate/delivery/verdict/ledger.json.gz', $project);

    expect($written)->toEqual(Written::to($file))
        ->and(LedgerFile::read((string) file_get_contents($file), LedgerLimits::standard()))->toEqual(deliveredLedger())
        ->and(deliveredIn($project, Stage::Verdict))->toEqual(Delivery::none()->withLedger(LedgerPost::to(Scope::branch('main'))));
});

it('names no scope where the ledger cannot be written, nor where the delivery cannot be read', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, '.mutation-gate/delivery/verdict/ledger.json.gz/kept', '');
    $unread = Scratch::directory();
    Scratch::write($unread, '.mutation-gate/delivery/verdict/delivery.json', '{}');

    expect(DeliveryDirectory::of(Directory::at($project), Stage::Verdict)->ledger(Scope::branch('main'), deliveredLedger()))
        ->toEqual(CannotJudge::because(sprintf('%s/.mutation-gate/delivery/verdict/ledger.json.gz could not be written.', $project)))
        ->and(deliveredIn($project, Stage::Verdict))->toBe('nothing')
        ->and(DeliveryDirectory::of(Directory::at($unread), Stage::Verdict)->ledger(Scope::branch('main'), deliveredLedger()))
        ->toEqual(CannotJudge::because('The delivery cannot be read, so deliver sends nothing: delivery.format is missing.'));
});

it('reads ledgers from the store, and writes them beside the delivery alone', function (): void {
    $project = Scratch::directory();
    $store = new ProofStoreFake()->keeping(Scope::branch('main'), deliveredLedger());
    $ledgers = DeliveredLedger::over($store, DeliveryDirectory::of(Directory::at($project), Stage::Verdict));
    $blocked = Scratch::directory();
    Scratch::write($blocked, '.mutation-gate/delivery/verdict/ledger.json.gz/kept', '');

    expect($ledgers->read(Scope::branch('main')))->toEqual(deliveredLedger())
        ->and($ledgers->write(Scope::pullRequest(7), deliveredLedger()))
        ->toEqual(Written::noting(
            sprintf('%s/.mutation-gate/delivery/verdict/ledger.json.gz', $project),
            'It is not in the store until deliver writes it there for refs/pull/7.',
        ))
        ->and($store->asked())->toBe(['read refs/heads/main'])
        ->and(DeliveredLedger::over($store, DeliveryDirectory::of(Directory::at($blocked), Stage::Verdict))->write(Scope::branch('main'), deliveredLedger()))
        ->toEqual(NotWritten::because(sprintf('%s/.mutation-gate/delivery/verdict/ledger.json.gz could not be written.', $blocked)));
});

it('reads what the store keeps beside a ledger, and keeps what it is given beside the delivery alone', function (): void {
    $project = Scratch::directory();
    $store = new ProofStoreFake();
    $store->keep(Scope::branch('main'), Companion::Coverage, Contents::of('the default branch\'s map'));
    $ledgers = DeliveredLedger::over($store, DeliveryDirectory::of(Directory::at($project), Stage::Verdict));
    $blocked = Scratch::directory();
    Scratch::write($blocked, '.mutation-gate/delivery/verdict/coverage.json.gz/kept', '');
    $unread = Scratch::directory();
    Scratch::write($unread, '.mutation-gate/delivery/verdict/delivery.json', '{}');

    expect($ledgers->companion(Scope::branch('main'), Companion::Coverage))->toEqual(Contents::of('the default branch\'s map'))
        ->and($ledgers->keep(Scope::branch('main'), Companion::Coverage, Contents::of('this run\'s map')))
        ->toEqual(Written::noting(
            sprintf('%s/.mutation-gate/delivery/verdict/coverage.json.gz', $project),
            'It is not in the store until deliver keeps it there for refs/heads/main.',
        ))
        ->and(file_get_contents(sprintf('%s/.mutation-gate/delivery/verdict/coverage.json.gz', $project)))->toBe('this run\'s map')
        ->and(deliveredIn($project, Stage::Verdict))->toEqual(Delivery::none()->withKept(KeptPost::of(Companion::Coverage, Scope::branch('main'))))
        ->and($store->companion(Scope::branch('main'), Companion::Coverage))->toEqual(Contents::of('the default branch\'s map'))
        ->and(DeliveredLedger::over($store, DeliveryDirectory::of(Directory::at($blocked), Stage::Verdict))->keep(Scope::branch('main'), Companion::Coverage, Contents::of('x')))
        ->toEqual(NotWritten::because(sprintf('%s/.mutation-gate/delivery/verdict/coverage.json.gz could not be written.', $blocked)))
        ->and(deliveredIn($blocked, Stage::Verdict))->toBe('nothing')
        ->and(DeliveryDirectory::of(Directory::at($unread), Stage::Verdict)->kept(Scope::branch('main'), Companion::Coverage, Contents::of('x')))
        ->toEqual(CannotJudge::because('The delivery cannot be read, so deliver sends nothing: delivery.format is missing.'));
});

it('adds what a reporter would send to the delivery, rather than send it', function (): void {
    $project = Scratch::directory();
    $commenting = new class implements Deferring {
        public function deferred(Verdict $verdict, Delivery $delivery): Delivery
        {
            return $delivery->withComment($verdict->judgement()->value);
        }
    };
    $report = DeliveredReport::of($commenting, DeliveryDirectory::of(Directory::at($project), Stage::Verdict));
    $unread = Scratch::directory();
    Scratch::write($unread, '.mutation-gate/delivery/verdict/delivery.json', '{}');

    expect($report->report(Verdicts::passing()))->toEqual(Written::to(sprintf('%s/.mutation-gate/delivery/verdict/delivery.json', $project)))
        ->and(deliveredIn($project, Stage::Verdict))->toEqual(Delivery::none()->withComment(Verdicts::passing()->judgement()->value))
        ->and(DeliveredReport::of($commenting, DeliveryDirectory::of(Directory::at($unread), Stage::Verdict))->report(Verdicts::passing()))
        ->toEqual(NotWritten::because('The delivery cannot be read, so deliver sends nothing: delivery.format is missing.'));
});
