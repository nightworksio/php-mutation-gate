<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Dependents;
use NightWorksIO\MutationGate\Cli\Flow\NameGraph;
use NightWorksIO\MutationGate\Core\Analysis\DependentCap;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

const DEPENDENTS_MONEY = "<?php\n\nfinal class Money\n{\n    public function add(int \$cents): Money\n    {\n        return new Money(\$cents + 1);\n    }\n}\n";

/**
 * The dependents of mutants in a project holding Money, and these files
 * besides, each written and in git's working tree.
 *
 * @param array<string, string> $files what each other file holds, by its path
 */
function dependentsIn(array $files, object ...$ports): Dependents
{
    $all = ['src/Money.php' => DEPENDENTS_MONEY, ...$files];
    $project = Scratch::directory();

    foreach ($all as $path => $contents) {
        Scratch::write($project, $path, $contents);
    }

    $checkout = new ChangeSourceFake(Revision::ref('base'), Changes::none(), [Revision::workingTree()->name() => $all]);

    return new Dependents(new NameGraph(Flows::adapters($project, [], $checkout, ...$ports)), DependentCap::standard());
}

/** @return list<string> */
function dependentsOf(Dependents $dependents, string $mutant): array
{
    return array_map(
        static fn(Path $file): string => $file->value(),
        [...$dependents->of(Path::of('src/Money.php'), Contents::of(DEPENDENTS_MONEY), Contents::of($mutant))],
    );
}

it('finds none for a mutant that changes only a body', function (): void {
    $dependents = dependentsIn(['src/Wallet.php' => "<?php\n\nfinal class Wallet\n{\n    public function total(Money \$m): int { return \$m->add(1); }\n}\n"]);

    expect(dependentsOf($dependents, str_replace('$cents + 1', '$cents - 1', DEPENDENTS_MONEY)))->toBe([]);
});

it('finds every file but its own that mentions what the file declares, for a mutant that changes a declaration, in path order', function (): void {
    $dependents = dependentsIn([
        'src/Wallet.php' => "<?php\n\nfinal class Wallet\n{\n    public function total(Money \$m): int { return \$m->add(1); }\n}\n",
        'lib/Bank.php' => "<?php\n\nfinal class Bank\n{\n    public function open(): Money { return new Money(); }\n}\n",
        'src/Unrelated.php' => "<?php\n\nfinal class Unrelated\n{\n}\n",
    ]);

    expect(dependentsOf($dependents, str_replace('public function add', 'protected function add', DEPENDENTS_MONEY)))
        ->toBe(['lib/Bank.php', 'src/Wallet.php']);
});

it('finds no more than the cap allows', function (): void {
    $files = [];

    foreach (range(1, 30) as $n) {
        $files[sprintf('src/User%02d.php', $n)] = sprintf("<?php\n\nfinal class User%02d\n{\n    public Money \$money;\n}\n", $n);
    }

    $found = dependentsOf(dependentsIn($files), str_replace('int $cents', 'float $cents', DEPENDENTS_MONEY));

    expect($found)->toHaveCount(25)
        ->and($found[0])->toBe('src/User01.php')
        ->and($found[24])->toBe('src/User25.php');
});

it('finds none where the project\'s files cannot be read, so the check sees its own file alone', function (): void {
    $dependents = dependentsIn(
        ['src/Wallet.php' => "<?php\n\nfinal class Wallet\n{\n    public Money \$money;\n}\n"],
        new TreeSourceFake(CannotJudge::because('No trees.')),
    );

    expect(dependentsOf($dependents, str_replace('public function add', 'protected function add', DEPENDENTS_MONEY)))->toBe([])
        ->and(dependentsOf($dependents, str_replace('int $cents', 'float $cents', DEPENDENTS_MONEY)))->toBe([]);
});
