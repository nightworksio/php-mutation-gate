<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Heating;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Config\HeatmapRows;
use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Heat\FileHeat;
use NightWorksIO\MutationGate\Core\Heat\FunctionSpans;
use NightWorksIO\MutationGate\Core\Heat\Heat;
use NightWorksIO\MutationGate\Core\Heat\Heatmap;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Ignoring;
use NightWorksIO\MutationGate\Core\Verdict\Judge;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Support\Heatmaps;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * `src/Money.php`'s mutants on these lines, each killed or survived as it says.
 *
 * @param array<int, MutantStatus> $at by line
 */
function heatingMutants(array $at): Mutants
{
    $mutants = [];

    foreach ($at as $line => $status) {
        $mutants[] = Mutant::of(
            MutantId::hash(Path::of('src/Money.php'), 'LessThan', sprintf('%d', $line), 0),
            sprintf('native-%d', $line),
            Location::of(Path::of('src/Money.php'), Line::of($line), Line::of($line)),
            Mutation::of('LessThan', MutatorFamily::Boundary, ''),
            $status,
            Unmeasured::duration(),
        );
    }

    return Mutants::of(...$mutants);
}

$trees = static fn(): Trees => Trees::of(Tree::at(Path::of('src'), Floor::of(50), Package::at(Path::root())));
$judge = static fn(): Judge => Judge::of($trees(), Baseline::none(), Reach::nothing(Packages::of($trees())), Uncovered::Count, TimeoutMode::Confirm, Ignoring::none());
$run = static fn(): Run => Run::of('main', Instant::at(new DateTimeImmutable('2026-09-30T09:00:00Z')), Digest::of(str_repeat('b', 64)));

$functions = static fn(FileHeat $file): array => array_map(
    static fn(Heat $function): array => [
        $function->name(),
        $function->score() instanceof Score ? $function->score()->hundredths() : null,
        $function->base() instanceof Score ? $function->base()->hundredths() : null,
    ],
    [...$file->functions()],
);

it('scores each function as the file on disk holds it, against the functions the base\'s proof names', function () use ($judge, $run, $functions): void {
    $project = Scratch::directory();
    Scratch::write($project, 'src/Money.php', Heatmaps::MONEY);
    $unit = Unit::file(Path::of('src/Money.php'));
    $now = UnitResults::of(UnitResult::of($unit, Origin::Run, heatingMutants([6 => MutantStatus::Killed, 12 => MutantStatus::Survived])));
    $proof = Proof::of(Digest::sha256Of('money'), Path::of('src/Money.php'), heatingMutants([6 => MutantStatus::Killed, 12 => MutantStatus::Killed]), $run())
        ->withFunctions(FunctionSpans::in(Contents::of(Heatmaps::MONEY)));
    $heatmap = new Heating(Directory::at($project), HeatmapRows::of(files: 3, comment: 0))
        ->of($judge()->trees($now), $now, $judge(), Proofs::of($proof));
    [$money] = [...$heatmap];

    expect($functions($money))->toBe([['Money::sub', 0, 10_000], ['Money::add', 10_000, 10_000]])
        ->and($money->heat()->base())->toEqual(Score::ofHundredths(10_000))
        ->and($heatmap->lowest())->toHaveCount(1)
        ->and([...$heatmap->fallen()])->toBe([]);
});

it('scores no function where functions are not scored, the base\'s proof names none, or it never proved the unit', function () use ($judge, $run, $functions): void {
    $project = Scratch::directory();
    Scratch::write($project, 'src/Money.php', Heatmaps::MONEY);
    $unit = Unit::file(Path::of('src/Money.php'));
    $now = UnitResults::of(UnitResult::of($unit, Origin::Run, heatingMutants([6 => MutantStatus::Killed])));
    $unnamed = Proof::of(Digest::sha256Of('money'), Path::of('src/Money.php'), heatingMutants([6 => MutantStatus::Killed]), $run());
    $named = $unnamed->withFunctions(FunctionSpans::in(Contents::of(Heatmaps::MONEY)));
    $heated = static fn(HeatmapRows $rows, Proofs $base, string $directory): Heatmap => new Heating(Directory::at($directory), $rows)
        ->of($judge()->trees($now), $now, $judge(), $base);

    expect($functions([...$heated(HeatmapRows::of(methods: false), Proofs::of($named), $project)][0]))->toBe([])
        ->and($functions([...$heated(HeatmapRows::of(), Proofs::of($unnamed), $project)][0]))->toBe([['Money::add', 10_000, null]])
        ->and([...$heated(HeatmapRows::of(), Proofs::none(), $project)][0]->heat()->base())->not->toBeInstanceOf(Score::class)
        ->and($functions([...$heated(HeatmapRows::of(), Proofs::of($named), Scratch::directory())][0]))->toBe([]);
});
