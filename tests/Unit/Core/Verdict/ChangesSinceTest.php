<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Reach\FileRoles;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\ChangeReach;
use NightWorksIO\MutationGate\Core\Verdict\ChangesSince;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;

$commit = static fn(string $word): Revision => Revision::ref(mb_substr(hash('sha256', $word), 0, 40));
$kill = static fn(string $unit): ProvedKill => ProvedKill::of(MutantId::hash(Path::of($unit), 'Plus', '1', 0), Path::of($unit), Line::of(1), 'Plus', TestIds::none());
$killed = static fn(string $unit): Mutant => Mutant::of(
    MutantId::hash(Path::of($unit), 'Plus', '2', 0),
    '2',
    Location::of(Path::of($unit), Line::of(2), Line::of(2)),
    Mutation::of('Plus', MutatorFamily::Arithmetic, ''),
    MutantStatus::Killed,
    Unmeasured::duration(),
);
/** The proof of a unit, at a base, with these kills and mutants, whose digests were taken at a commit, where named. */
$proof = static fn(string $unit, string $base, ProvedKills $kills, Mutants $mutants, Inputs|Undigested $inputs): Proof => Proof::held(
    Digest::sha256Of($unit),
    Path::of($unit),
    $mutants,
    $kills,
    Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of($base)),
)->withInputs($inputs);
$makeInputs = static fn(): Inputs => Inputs::of(Digest::sha256Of('source'), Digest::sha256Of('mutation'));
$makeRoles = static fn(): FileRoles => FileRoles::of(Layout::standard(Paths::none()), Packages::of(Flows::trees()), Paths::none());

it('reads the commit of each result established at another base that holds a kill, by a test or by static analysis, each commit once', function () use ($commit, $kill, $killed, $proof, $makeInputs): void {
    $inputs = $makeInputs();

    $rejected = $killed('src/Checked.php')->rejected(Rejection::by('phpstan', Finding::error(Path::of('src/Checked.php'), 'return.type', 'No.')));
    $survived = Mutant::of(
        MutantId::hash(Path::of('src/Survived.php'), 'Plus', '2', 0),
        '2',
        Location::of(Path::of('src/Survived.php'), Line::of(2), Line::of(2)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, ''),
        MutantStatus::Survived,
        Unmeasured::duration(),
    );
    $proofs = Proofs::of(
        $proof('src/Money.php', 'old', ProvedKills::of($kill('src/Money.php')), Mutants::none(), $inputs->takenAt($commit('first'))),
        $proof('src/Tax.php', 'old', ProvedKills::none(), Mutants::of($killed('src/Tax.php')), $inputs->takenAt($commit('first'))),
        $proof('src/Rate.php', 'older', ProvedKills::of($kill('src/Rate.php')), Mutants::none(), $inputs->takenAt($commit('second'))),
        $proof('src/Same.php', 'base', ProvedKills::of($kill('src/Same.php')), Mutants::none(), $inputs->takenAt($commit('same base'))),
        $proof('src/Spared.php', 'old', ProvedKills::none(), Mutants::none(), $inputs->takenAt($commit('no kill'))),
        $proof('src/Checked.php', 'old', ProvedKills::none(), Mutants::of($rejected), $inputs->takenAt($commit('third'))),
        $proof('src/Survived.php', 'old', ProvedKills::none(), Mutants::of($survived), $inputs->takenAt($commit('survivor'))),
        $proof('src/Dirty.php', 'old', ProvedKills::of($kill('src/Dirty.php')), Mutants::none(), $inputs),
        $proof('src/Old.php', 'old', ProvedKills::of($kill('src/Old.php')), Mutants::none(), Undigested::proof()),
    );
    $units = Units::of(...array_map(
        static fn(string $unit): Unit => Unit::file(Path::of($unit)),
        ['src/Money.php', 'src/Tax.php', 'src/Rate.php', 'src/Same.php', 'src/Spared.php', 'src/Checked.php', 'src/Survived.php', 'src/Dirty.php', 'src/Old.php', 'src/New.php'],
    ));

    expect(ChangesSince::commitsOf($units, $proofs->newest(), Digest::sha256Of('base')))->toEqual([$commit('first'), $commit('second'), $commit('third')]);
});

it('answers what changed since a commit it read, and cannot tell for one it did not', function () use ($commit, $makeRoles): void {
    $roles = $makeRoles();

    $reach = ChangeReach::of(Changes::none(), ByPath::none(), ByPath::none(), NamedFiles::byName([], []), $roles);
    $since = ChangesSince::none()->with($commit('read'), $reach);

    expect($since->at($commit('read')))->toBe($reach)
        ->and($since->at($commit('unread')))->toEqual(CannotTell::because(sprintf('What changed since %s was not read.', $commit('unread')->name())));
});

it('warns why no kill proved at a commit carries, where git cannot say what changed or the change reaches every kill', function () use ($commit, $makeRoles): void {
    $roles = $makeRoles();

    $none = NamedFiles::byName([], []);
    $since = ChangesSince::none()
        ->with($commit('shallow'), CannotTell::because('The clone is shallow.'))
        ->with($commit('lock'), ChangeReach::of(Changes::of(Change::modified(Path::of('composer.lock'), Lines::none())), ByPath::none(), ByPath::none(), $none, $roles))
        ->with($commit('followed'), ChangeReach::of(Changes::of(Change::modified(Path::of('docs/guide.md'), Lines::none())), ByPath::none(), ByPath::none(), $none, $roles));

    expect($since->warnings())->toEqual(Warnings::of(
        Warning::that(sprintf('No kill proved at %s carries for a unit the budget never started. The clone is shallow.', $commit('shallow')->name())),
        Warning::that(sprintf(
            'No kill proved at %s carries for a unit the budget never started. `composer.lock` decides how the gate runs, so nothing judged before it stands.',
            $commit('lock')->name(),
        )),
    ))
        ->and(ChangesSince::none()->warnings())->toEqual(Warnings::none());
});
