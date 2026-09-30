<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Order;

use function array_flip;
use function array_map;
use function file_get_contents;
use function file_put_contents;
use function is_file;

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Proof\KillersRecord;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;

use function sprintf;

/**
 * The kill history the adapter hands the plugin in Pest's own process, as a
 * ledger's `tests` and `killers` sections hold it, in the order directory.
 * A plan that cannot be read is no history: every mutant runs its tests
 * fastest first.
 */
final readonly class Plan
{
    /** The plan's name in the order directory. */
    private const string FILE = 'plan.json';

    /**
     * A mutation run that has the plugin write each mutant's order, where the
     * request puts the likely killers first: the history planned in a fresh
     * order directory, named to the plugin.
     */
    public static function handedOver(
        Project $project,
        MutationRequest $request,
        Command|CannotJudge $command,
    ): Command|CannotJudge {
        if ($command instanceof CannotJudge || ! $request->ordering()->putsKillersFirst()) {
            return $command;
        }

        $order = $project->freshOrder();

        if ($order instanceof CannotJudge) {
            return $order;
        }

        self::write($order, $request->ordering()->history());

        return $command->with([Seeder::ORDER => $order]);
    }

    public static function write(string $directory, KillHistory $history): void
    {
        $tests = KillersRecord::testsOf($history);
        $killers = KillersRecord::of($history, array_flip($tests));
        $plan = [LedgerFile::TESTS => $tests, KillersRecord::SECTION => $killers];

        file_put_contents(self::in($directory), JsonText::compact($plan));
    }

    public static function read(string $directory): KillHistory
    {
        $file = self::in($directory);
        $plan = Node::decode(is_file($file) ? sprintf('%s', file_get_contents($file)) : '');

        try {
            $listed = $plan->field(LedgerFile::TESTS)->items();
            $tests = array_map(static fn(Node $test): string => $test->text(), $listed);
        } catch (NotInShape) {
            return KillHistory::none();
        }

        return KillersRecord::read($plan->field(KillersRecord::SECTION), $tests);
    }

    /** Where a plan is in an order directory. */
    public static function in(string $directory): string
    {
        return sprintf('%s/%s', $directory, self::FILE);
    }
}
