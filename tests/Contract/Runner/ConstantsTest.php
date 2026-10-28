<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Removal\RemoveArrayItem;

$holds = [
    'holds:src/Adapter/Pest',
];

it('kills the items of a constant array a test reads through its class, running no line of the source, with Pest patched', function (): void {
    $copied = ['src/Codes.php', 'src/Code.php', 'tests/CodesSpec.php'];

    foreach ($copied as $file) {
        copy(Tree::at(sprintf('tests/Contract/Runner/constants/%s', $file)), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $file)));
    }

    Patch::applyIn(Library::vendor());
    $runner = Library::pest(Patching::on(Library::canary()))->runner();
    $codes = Paths::of(Path::of('src/Codes.php'));

    try {
        $map = $runner->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/constants')));
        file_put_contents(
            Tree::at(sprintf('%s/%s', Library::DIRECTORY, CoverageMapFile::in(Path::of('.mutation-gate/constants'))->value())),
            CoverageMapFile::encode($map instanceof CoverageMap ? $map : CoverageMap::empty(), Unplaced::map()),
        );
        $result = $runner->mutate(MutationRequest::of($codes, WholeSuite::tests())
            ->narrowedTo($codes, Narrowing::none()->toMutators(Mutators::named(RemoveArrayItem::class)))
            ->reusingCoverage(Handed::maps(Path::of('.mutation-gate/constants'), Path::of('.mutation-gate/constants'))));
    } finally {
        foreach ($copied as $file) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $file)));
        }
    }

    expect($map instanceof CoverageMap ? $map->tests()->has(TestId::of('P\Tests\CodesSpec::__pest_evaluable_it_places_every_code_in_exactly_one_list')) : $map)
        ->toBeTrue()
        ->and($result instanceof CannotJudge ? $result->why() : array_map(
            static fn(Mutant $mutant): array => [$mutant->location()->start()->number(), $mutant->status()],
            [...$result->mutants()],
        ))->toEqualCanonicalizing([
            [11, MutantStatus::Killed],
            [12, MutantStatus::Killed],
            [16, MutantStatus::Killed],
        ]);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);
