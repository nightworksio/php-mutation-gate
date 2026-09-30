<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Sources;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Tests\Support\Clustered;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads each file a survivor that asks for a test is in once, and no file of a killed or flaky mutant or one that cannot be read', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'src/Cart.php', Clustered::CART);
    Scratch::write($project, 'src/Paid.php', "<?php\n");
    Scratch::write($project, 'src/Flaky.php', "<?php\n");
    $mutant = static fn(string $at, MutantJudgement $judgement): JudgedMutant => JudgedMutant::of(
        Verdicts::mutant($at, 'Plus', MutatorFamily::Arithmetic, ''),
        $judgement,
    );
    $trees = TreeVerdicts::of(TreeVerdict::judged(
        Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())),
        Unrecorded::floor(),
        JudgedUnits::none(),
        JudgedMutants::of(
            $mutant('src/Cart.php:7', MutantJudgement::Survived),
            $mutant('src/Cart.php:16', MutantJudgement::Survived),
            $mutant('src/Paid.php:1', MutantJudgement::Killed),
            $mutant('src/Flaky.php:1', MutantJudgement::Flaky),
            $mutant('src/Gone.php:1', MutantJudgement::Survived),
        ),
        Uncovered::Count,
    ));
    $sources = Sources::ofSurvivors($trees, Directory::at($project));

    expect($sources)->toHaveCount(1)
        ->and($sources->at(Path::of('src/Cart.php'), Contents::of('')))->toEqual(Contents::of(Clustered::CART));
});
