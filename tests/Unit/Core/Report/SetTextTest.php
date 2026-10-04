<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\SetText;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Tests\Support\Judged;
use NightWorksIO\MutationGate\Tests\Support\Secured;

$tree = static fn(Floor|Exempt|Undeclared $floor, JudgedMutants $mutants): TreeVerdict => TreeVerdict::judged(
    Tree::at(Path::of('src'), $floor, Package::at(Path::root())),
    Unrecorded::floor(),
    JudgedUnits::none(),
    $mutants,
    Uncovered::Count,
);

it('says what a tree scored against its floor', function (Floor|Exempt|Undeclared $floor, JudgedMutants $mutants, string $said) use ($tree): void {
    expect(SetText::tree($tree($floor, $mutants)))->toBe($said);
})->with([
    'below' => [Floor::of(80), Judged::mutants(MutantJudgement::Killed, MutantJudgement::Survived), 'src scores 50.00%, below its floor of 80.00%.'],
    'met exactly' => [Floor::of(50), Judged::mutants(MutantJudgement::Killed, MutantJudgement::Survived), 'src scores 50.00% against its floor of 50.00%.'],
    'no floor' => [Undeclared::floor(), Judged::mutants(MutantJudgement::Killed), 'src scores 100.00%, and no floor holds it.'],
    'nothing to mutate' => [Floor::of(80), JudgedMutants::none(), 'src has nothing to mutate.'],
    'exempt' => [Exempt::because('Replaced'), JudgedMutants::none(), 'src is exempt: Replaced'],
]);

it('says how a tree changed against the base, where both have a score', function () use ($tree): void {
    $killed = Judged::mutants(MutantJudgement::Killed, MutantJudgement::Survived);

    expect(SetText::tree($tree(Floor::of(50), $killed)->comparedWith(Score::ofHundredths(4_880))))
        ->toBe('src scores 50.00% against its floor of 50.00%. That is +1.20 against the base.')
        ->and(SetText::tree($tree(Floor::of(50), $killed)->comparedWith(NothingToMutate::found())))
        ->toBe('src scores 50.00% against its floor of 50.00%.')
        ->and(SetText::tree($tree(Floor::of(50), JudgedMutants::none())->comparedWith(Score::ofHundredths(4_880))))
        ->toBe('src has nothing to mutate.');
});

it('says what new code scored, named by its package where it is not the root', function (): void {
    $root = NewCodeVerdict::judged(Package::at(Path::root()), Floor::of(100), Judged::mutants(MutantJudgement::Survived), Uncovered::Count);
    $module = NewCodeVerdict::judged(Package::at(Path::of('modules/Billing')), Floor::of(90), Judged::mutants(MutantJudgement::Killed), Uncovered::Count);

    expect(SetText::newCode($root))->toBe('New code scores 0.00%, below its floor of 100.00%.')
        ->and(SetText::newCode($module))->toBe('New code in modules/Billing scores 100.00% against its floor of 90.00%.')
        ->and(SetText::newCodeName($module))->toBe('New code in modules/Billing');
});

it('says what the project scored, or that it has nothing to mutate', function (): void {
    expect(SetText::project(Score::ofHundredths(8_741)))->toBe('The project scores 87.41%.')
        ->and(SetText::project(NothingToMutate::found()))->toBe('The project has nothing to mutate.');
});

it('prints a floor, an exemption, or none', function (): void {
    expect(SetText::floor(Floor::of(80)))->toBe('80.00%')
        ->and(SetText::floor(Exempt::because('Replaced')))->toBe('exempt')
        ->and(SetText::floor(Undeclared::floor()))->toBe('none')
        ->and(SetText::floor(Unrecorded::floor()))->toBe('none');
});

it('says what a security set scored, named by its package where it is not the root', function (): void {
    $root = Secured::set('.', Floor::of(100), Unrecorded::floor(), Secured::mutant(MutantJudgement::Survived));
    $billing = Secured::set('packages/billing', Undeclared::floor(), Floor::of(90), Secured::mutant(MutantJudgement::Killed));

    expect(SetText::security($root))->toBe('Security scores 0.00%, below its floor of 100.00%.')
        ->and(SetText::security($billing))->toBe('Security in packages/billing scores 100.00% against its floor of 90.00%.')
        ->and(SetText::securityName($billing))->toBe('Security in packages/billing')
        ->and(SetText::security(Secured::set('.', Undeclared::floor(), Unrecorded::floor())))->toBe('Security has nothing to mutate.');
});

it('says a tree\'s, a package\'s and an exempt reason\'s text as one plain line, with no control character', function (): void {
    $hostile = TreeVerdict::judged(
        Tree::at(Path::of("src/\e[31mred\nx"), Exempt::because("Replaced\e[0m\nlater"), Package::at(Path::root())),
        Unrecorded::floor(),
        JudgedUnits::none(),
        JudgedMutants::none(),
        Uncovered::Count,
    );

    expect(SetText::tree($hostile))->toBe('src/[31mred x is exempt: Replaced[0m later')
        ->and(SetText::securityName(Secured::set("packages/\e[2Jbilling", Floor::of(100), Unrecorded::floor())))
        ->toBe('Security in packages/[2Jbilling');
});
