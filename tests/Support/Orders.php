<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function file_get_contents;
use function is_file;

use LogicException;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Plan;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Seed;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Seeder;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Test\TestId;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\MutationSuite;

use function realpath;
use function sprintf;

/**
 * A project the plugin writes orders in: src/Money.php, whose line 11 is in
 * `add` and line 20 in no function, an opening run's map in which the
 * `adds` test covers line 11 and the `rows` test with a data set's row line
 * 20, and a plan whose history names the `twice` test for `add`.
 */
final readonly class Orders
{
    public const string ADDS = 'P\Tests\MoneySpec::__pest_evaluable_it_adds';

    public const string ROWS = 'P\Tests\MoneySpec::__pest_evaluable_it_rows#(1)';

    public const string TWICE = 'P\Tests\MoneySpec::__pest_evaluable_it_adds_twice';

    /** @return array{Seeder, MutationSuite, string} the seeder, the suite Pest made and the order directory */
    public static function project(bool $mapped): array
    {
        $root = (string) realpath(Scratch::directory());
        $source = "<?php\n\nnamespace Library;\n\nfinal class Money\n{\n    public function add(int \$a, int \$b): int\n    {\n"
            . "        \$sum = 0;\n\n        return \$a + \$b;\n    }\n}\n\n\n\n\n\n\n\$fallback = 1 + 1;\n";
        Scratch::write($root, 'src/Money.php', $source);
        $results = sprintf('%s/.mutation-gate/pest/results.jsonl', $root);
        $order = sprintf('%s/.mutation-gate/order', $root);
        Scratch::write($root, '.mutation-gate/order/.keep', '');

        if ($mapped) {
            Scratch::write($root, '.mutation-gate/pest/.keep', '');
            CoverageMaps::write(
                Recorder::coverageBeside($results),
                $root,
                ['src/Money.php' => [11 => [0], 20 => [1]]],
                [self::ADDS, self::ROWS],
                [self::ADDS => 0.5, self::ROWS => 0.25],
            );
        }

        Plan::write($order, KillHistory::none()->withFunction(
            Enclosing::named(Path::of('src/Money.php'), 'add'),
            Ranking::of(Kills::of(TestId::of(self::TWICE), 2), Kills::of(TestId::of(self::ADDS), 1)),
        ));

        $suite = new MutationSuite();
        $suite->repository->add(Mutations::mutation(sprintf('%s/src/Money.php', $root), 'n1', 11, '/tmp/mutations/m1'));
        $suite->repository->add(Mutations::mutation(sprintf('%s/src/Money.php', $root), 'n2', 20, '/tmp/mutations/m2'));
        $seeder = Seeder::listening($order, $results, mutant: false, events: new Facade(), root: Root::of($root));

        return [$seeder instanceof Seeder ? $seeder : throw new LogicException('not seeding'), $suite, $order];
    }

    /** The order the plugin wrote for a mutant, by its mutated copy, or nothing where it wrote none. */
    public static function seeded(string $order, string $mutated): string
    {
        $file = sprintf('%s/%s', Seed::directoryOf($order, $mutated), Seed::HISTORY);

        return is_file($file) ? (string) file_get_contents($file) : '';
    }
}
