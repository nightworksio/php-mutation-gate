<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\Check\SonarDrops;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Doctor\SonarSources;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$makeTrees = static fn(): Trees => Trees::of(
    Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())),
    Tree::at(Path::of('app/Legacy'), Floor::of(50), Package::at(Path::root())),
    Tree::at(Path::of('lib'), Floor::of(50), Package::at(Path::root())),
);
$reporting = static fn(string ...$reporters): Observations => Observations::none()
    ->withSettings(Configs::settings(['runner' => 'pest', 'reports' => array_map(
        static fn(string $use): array => ['use' => $use, 'path' => sprintf('build/%s.json', $use)],
        $reporters,
    )]))
    ->withTrees($makeTrees())
    ->withFiles(ProjectFiles::none()->withSonarSources(SonarSources::of(Path::of('src'))));
$finding = static fn(string $tree): Finding => Finding::of(
    Slug::OutsideSonarSources,
    Severity::Advice,
    sprintf('Sonar drops the survivors in `%s`, which is outside `sonar.sources`.', $tree),
    'SonarQube indexes only the files under sonar.sources, and drops an imported issue on any other file.',
    sprintf('Add %s to sonar.sources in sonar-project.properties, or leave it out of the trees.', $tree),
);

it('advises of each tree outside sonar.sources where a sonar report is written', function () use ($reporting, $finding): void {
    expect(SonarDrops::in($reporting('json', 'sonar')))->toEqual(Findings::of($finding('app/Legacy'), $finding('lib')));
});

it('finds nothing where no sonar report is written, every tree is inside, or nothing was observed', function () use ($reporting, $makeTrees): void {
    $trees = $makeTrees();

    $inside = $reporting('sonar')->withFiles(ProjectFiles::none()->withSonarSources(SonarSources::of(Path::of('src'), Path::of('app'), Path::of('lib'))));

    expect(SonarDrops::in($reporting('json', 'sarif')))->toEqual(Findings::none())
        ->and(SonarDrops::in($inside))->toEqual(Findings::none())
        ->and(SonarDrops::in($reporting('sonar')->withFiles(ProjectFiles::none())))->toEqual(Findings::none())
        ->and(SonarDrops::in(Observations::none()->withTrees($trees)->withFiles(ProjectFiles::none()->withSonarSources(SonarSources::of(Path::of('src'))))))->toEqual(Findings::none())
        ->and(SonarDrops::in(Observations::none()))->toEqual(Findings::none());
});

it('finds nothing where the trees could not be found', function () use ($reporting): void {
    expect(SonarDrops::in($reporting('sonar')->withTrees(CannotJudge::because('no tree'))))->toEqual(Findings::none());
});
