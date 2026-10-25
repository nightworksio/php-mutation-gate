<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Since;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\ChangeReach;
use NightWorksIO\MutationGate\Port\TreeSource;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads nothing where no result needs what changed since its commit', function (): void {
    $unread = new class implements TreeSource {
        public function trees(): Trees|CannotJudge
        {
            throw new LogicException('The trees were read.');
        }
    };

    expect(new Since(Flows::adapters(Scratch::directory(), [], $unread), Flows::settings())->of()->warnings())->toHaveCount(0);
});

it('reads a renamed file as it was under its old path and as it is under its new one', function (): void {
    $project = Flows::project();
    $before = [
        ...Flows::FILES,
        'src/Money.php' => "<?php\nfinal class Money\n{\n    use Equals;\n}\n",
        'src/Tax.php' => "<?php\nfinal class Tax\n{\n    use Same;\n}\n",
        'src/Equals.php' => "<?php\ntrait Equals\n{\n}\n",
    ];
    $now = [...$before, 'src/Same.php' => "<?php\ntrait Same\n{\n}\n"];
    unset($now['src/Equals.php']);

    foreach ($now as $path => $contents) {
        Scratch::write($project, $path, $contents);
    }

    $renamed = Changes::of(Change::renamed(Path::of('src/Equals.php'), Path::of('src/Same.php'), Lines::none()));
    $commit = Revision::ref('base');
    $checkout = new ChangeSourceFake($commit, $renamed, [Revision::workingTree()->name() => $now, 'base' => $before]);
    $reach = new Since(Flows::adapters($project, [], $checkout), Flows::settings())->of($commit)->at($commit);

    expect($reach instanceof ChangeReach
        ? [$reach->reaches(Path::of('src/Money.php'), Paths::none()), $reach->reaches(Path::of('src/Tax.php'), Paths::none())]
        : $reach)->toBe([true, true]);
});
