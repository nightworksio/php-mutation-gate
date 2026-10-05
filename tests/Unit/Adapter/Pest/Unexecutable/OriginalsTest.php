<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Original;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Originals;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\Codebase;
use NightWorksIO\MutationGate\Core\Php\Source;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$project = static function (): Project {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', "<?php\nfunction add(){return 1+1;}\n");
    Scratch::write($root, 'src/Broken.php', "<?php\nfunction (\n");

    return Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));
};

it('reads a file\'s original printed as Pest prints it and scanned, why it cannot be printed, or none where it cannot be read', function () use ($project): void {
    $originals = new Originals($project(), Codebase::of());
    $money = $originals->of(Path::of('src/Money.php'));
    $text = Contents::of("<?php\nfunction add(){return 1+1;}\n");

    expect($money)->toEqual(Original::of(
        Contents::of("<?php\n\nfunction add()\n{\n    return 1 + 1;\n}"),
        Source::read(Path::of('src/Money.php'), $text, test: false),
    ))
        ->and($originals->of(Path::of('src/Broken.php')))->toBeInstanceOf(CannotJudge::class)
        ->and($originals->of(Path::of('src/Gone.php')))->toEqual(NotGiven::value());
});

it('reads each file once, and answers it again as it read it, though the file changes after', function () use ($project): void {
    $at = $project();
    $originals = new Originals($at, Codebase::of());
    $first = $originals->of(Path::of('src/Money.php'));
    Scratch::write($at->root(), 'src/Money.php', "<?php\nfunction add(){return 2;}\n");

    expect($originals->of(Path::of('src/Money.php')))->toBe($first);
});

it('judges against the source the codebase already scanned, and scans a file it holds only as a test', function () use ($project): void {
    $at = $project();
    $text = Contents::of("<?php\nfunction add(){return 1+1;}\n");
    $scanned = Source::read(Path::of('src/Money.php'), $text, test: false);
    $asTest = Source::read(Path::of('src/Money.php'), $text, test: true);
    $money = new Originals($at, Codebase::of($scanned))->of(Path::of('src/Money.php'));
    $test = new Originals($at, Codebase::of($asTest))->of(Path::of('src/Money.php'));

    expect($money instanceof Original ? $money->source() : NotGiven::value())->toBe($scanned)
        ->and($test instanceof Original ? $test->source() : NotGiven::value())->toEqual($scanned);
});
