<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Printed;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$mutant = static fn(string $diff): Mutant => Mutant::of(
    MutantId::hash(Path::of('src/Money.php'), 'PlusToMinus', $diff, 0),
    'PlusToMinus',
    Location::of(Path::of('src/Money.php'), Line::of(2), Line::of(2)),
    Mutation::of('PlusToMinus', MutatorFamily::Arithmetic, $diff),
    MutantStatus::Survived,
    Seconds::of(0.1),
);
$project = static function (string $written): Project {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', $written);

    return Project::at($root, Paths::none(), Path::of('.gate'), Path::of('vendor'));
};
$answer = static fn(Checkable|CannotJudge $checkable): array => $checkable instanceof Checkable
    ? [$checkable->original() instanceof Contents ? $checkable->original()->text() : '', $checkable->mutant()->text()]
    : [$checkable->why()];

it('puts the diff onto the file as Pest prints it, and judges it against that print', function () use ($mutant, $project, $answer): void {
    $diff = "--- Original\n+++ New\n@@ @@\n {\n-    return 1 + 1;\n+    return 1 - 1;\n }";
    $printed = "<?php\n\nfunction add()\n{\n    return 1 + 1;\n}";

    expect($answer(Printed::checkable($project("<?php\nfunction add(){return 1+1;}\n"), $mutant($diff))))
        ->toBe([$printed, str_replace('1 + 1', '1 - 1', $printed)]);
});

it('cannot check a mutant whose file does not parse, is not there, or whose diff does not apply to the print', function () use ($mutant, $project, $answer): void {
    $applies = "@@ @@\n-    return 1 + 1;\n+    return 1 - 1;";

    $unparsed = $answer(Printed::checkable($project("<?php\nfunction add( {\n"), $mutant($applies)));
    $empty = Project::at(Scratch::directory(), Paths::none(), Path::of('.gate'), Path::of('vendor'));

    expect($unparsed)->toHaveCount(1)
        ->and(implode('', $unparsed))->toStartWith('src/Money.php does not parse, so its mutant cannot be printed as Pest prints it: Syntax error')
        ->and($answer(Printed::checkable($empty, $mutant($applies))))
        ->toBe(['The gate cannot read src/Money.php to check its mutant.'])
        ->and($answer(Printed::checkable($project("<?php\nfunction add(){return 2+2;}\n"), $mutant($applies))))
        ->toBe(['Its diff does not apply to src/Money.php as it is now.']);
});
