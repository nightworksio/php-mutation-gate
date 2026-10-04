<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Order;

use function file_get_contents;
use function file_put_contents;
use function is_file;

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Proof\KillHistoryFile;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;

use function sprintf;

/**
 * The kill history the adapter hands the plugin in Pest's own process, as a
 * kill history file holds it, in the order directory.
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
        if ($command instanceof CannotJudge || ! $request->search()->ordering()->putsKillersFirst()) {
            return $command;
        }

        $order = $project->freshOrder();

        if ($order instanceof CannotJudge) {
            return $order;
        }

        self::write($order, $request->search()->ordering()->history());

        return $command->with([GateVariable::Order->value => $order]);
    }

    public static function write(string $directory, KillHistory $history): void
    {
        file_put_contents(self::in($directory), KillHistoryFile::encode($history));
    }

    /** The history planned in an order directory; none where it holds none, or none that can be read. */
    public static function read(string $directory): KillHistory
    {
        $file = self::in($directory);
        $history = is_file($file) ? KillHistoryFile::decode(sprintf('%s', file_get_contents($file))) : null;

        return $history instanceof KillHistory ? $history : KillHistory::none();
    }

    /** Where a plan is in an order directory. */
    public static function in(string $directory): string
    {
        return sprintf('%s/%s', $directory, self::FILE);
    }
}
