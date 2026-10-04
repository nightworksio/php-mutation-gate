<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Measured;
use NightWorksIO\MutationGate\Config\Ignore;
use NightWorksIO\MutationGate\Config\Uncovered;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A proof of a file of the fixture, with the mutants the fake runner finds in it, established at this instant. */
$proofOf = static fn(string $file, string $at): Proof => Proof::of(
    Digest::sha256Of(sprintf('%s %s', $file, $at)),
    Path::of($file),
    Flows::mutantsOf($file),
    Run::of('local', Moment::at($at), Digest::sha256Of('base')),
);

/** @return list<string> each tree judged, with its score in hundredths */
$scores = static fn(Measured $measured): array => array_map(
    static fn(TreeVerdict $tree): string => sprintf(
        '%s %s',
        $tree->tree()->path()->value(),
        $tree->score() instanceof Score ? $tree->score()->hundredths() : 'nothing',
    ),
    [...$measured->trees()],
);

it('judges every tree over the newest result of each of its units', function () use ($proofOf, $scores): void {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()
        ->withProof($proofOf('src/Money.php', '2026-09-29T10:00:00Z'))
        ->withProof($proofOf('src/Held.php', '2026-09-29T10:00:00Z')));

    $measured = Measured::of(Flows::adapters(Flows::project(), [], $store), Flows::settings(), Baseline::none(), new DateTimeImmutable(Configs::NOW));

    expect($measured instanceof Measured ? $scores($measured) : $measured)->toBe(['src 4000'])
        ->and($measured instanceof Measured ? [...$measured->unmeasured()] : $measured)->toBe([]);
});

it('takes the run\'s own scope\'s newer results, and judges them against the baseline', function () use (
    $proofOf,
): void {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withProof($proofOf('src/Money.php', '2026-09-29T10:00:00Z')));
    $store->write(Scope::branch('feature'), Ledger::empty()
        ->withProof(Proof::of(Digest::sha256Of('newer'), Path::of('src/Money.php'), Mutants::none(), Run::of(
            'local',
            Moment::at('2026-09-30T10:00:00Z'),
            Digest::sha256Of('base'),
        )))
        ->withProof($proofOf('src/Held.php', '2026-09-29T10:00:00Z')));
    $ci = new CiPlanFake(RunOn::at(Scope::branch('feature'), Scope::branch('main')));
    $baseline = Baseline::of(Entry::of(Path::of('src'), Floor::of(12.5)));

    $measured = Measured::of(Flows::adapters(Flows::project(), [], $store, $ci), Flows::settings(), $baseline, new DateTimeImmutable(Configs::NOW));
    $trees = $measured instanceof Measured ? [...$measured->trees()] : [];

    expect(count($trees))->toBe(1)
        ->and($trees[0]->score() instanceof Score ? $trees[0]->score()->hundredths() : $trees[0]->score())->toBe(0)
        ->and($trees[0]->baseline())->toEqual(Floor::of(12.5));
});

it('leaves a tree with a unit that has no result yet unmeasured', function () use ($proofOf): void {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withProof($proofOf('src/Money.php', '2026-09-29T10:00:00Z')));
    $root = Package::at(Path::root());
    $trees = new TreeSourceFake(Trees::of(
        Tree::at(Path::of('src/Money.php'), Floor::of(50), $root),
        Tree::at(Path::of('src/Held.php'), Floor::of(50), $root),
    ));

    $adapters = Flows::adapters(Flows::project(), [], $store, $trees);
    $measured = Measured::of($adapters, Flows::settings(), Baseline::none(), new DateTimeImmutable(Configs::NOW));

    expect($measured instanceof Measured ? array_map(
        static fn(TreeVerdict $tree): string => $tree->tree()->path()->value(),
        [...$measured->trees()],
    ) : $measured)->toBe(['src/Money.php'])
        ->and($measured instanceof Measured ? [...$measured->unmeasured()] : $measured)
        ->toEqual([Path::of('src/Held.php')]);
});

it('judges each package\'s security set whose every tree was measured, and names the packages of the rest', function () use ($proofOf): void {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withProof($proofOf('src/Money.php', '2026-09-29T10:00:00Z')));
    $trees = new TreeSourceFake(Trees::of(
        Tree::at(Path::of('src/Money.php'), Floor::of(50), Package::at(Path::root())),
        Tree::at(Path::of('src/Held.php'), Floor::of(50), Package::at(Path::of('src'))),
    ));
    $baseline = Baseline::none()->withSecurity(Entry::of(Path::of('src'), Floor::of(80)));
    $plus = NamedMutators::of('Plus');

    $measured = Measured::of(Flows::adapters(Flows::project(), [], $store, $trees, $plus), Flows::settings(), $baseline, new DateTimeImmutable(Configs::NOW));
    $none = Measured::of(Flows::adapters(Flows::project(), [], $store, $trees), Flows::settings(), $baseline, new DateTimeImmutable(Configs::NOW));
    $sets = $measured instanceof Measured ? [...$measured->security()] : [];

    expect(count($sets))->toBe(1)
        ->and($sets[0]->package()->path())->toEqual(Path::root())
        ->and($measured instanceof Measured ? [...$measured->unmeasuredSecurity()] : $measured)->toEqual([Path::of('src')])
        ->and($none instanceof Measured ? $none->security() : $none)->toHaveCount(0)
        ->and($none instanceof Measured ? $none->unmeasuredSecurity() : $none)->toHaveCount(0);
});

it('scores the results with uncovered mutants left out where the config says so', function () use (
    $proofOf,
    $scores,
): void {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()
        ->withProof($proofOf('src/Money.php', '2026-09-29T10:00:00Z'))
        ->withProof($proofOf('src/Held.php', '2026-09-29T10:00:00Z')));

    $measured = Measured::of(
        Flows::adapters(Flows::project(), [], $store),
        Flows::settings(Uncovered::excluded()),
        Baseline::none(),
        new DateTimeImmutable(Configs::NOW),
    );

    expect($measured instanceof Measured ? $scores($measured) : $measured)->toBe(['src 5000']);
});

it('scores the results with the survivors the config ignores left out, while their ignores last', function () use (
    $proofOf,
    $scores,
): void {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()
        ->withProof($proofOf('src/Money.php', '2026-09-29T10:00:00Z'))
        ->withProof($proofOf('src/Held.php', '2026-09-29T10:00:00Z')));

    $measured = Measured::of(
        Flows::adapters(Flows::project(), [], $store),
        Flows::settings(
            Ignore::mutant('49e02fb39669', 'The bound is never reached', '2026-09-30'),
            Ignore::mutator('arithmetic', 'src/Held.php', 'Both sums are the same'),
            Ignore::mutant('95e61bd8bf62', 'Covered by the integration suite', '2026-09-29'),
        ),
        Baseline::none(),
        new DateTimeImmutable(Configs::NOW),
    );

    expect($measured instanceof Measured ? $scores($measured) : $measured)->toBe(['src 6666']);
});

it('cannot judge where it cannot tell where the run stands', function (): void {
    expect(Measured::of(Flows::adapters(Flows::project(), [], Flows::lost()), Flows::settings(), Baseline::none(), new DateTimeImmutable(Configs::NOW)))
        ->toBeInstanceOf(CannotJudge::class);
});
