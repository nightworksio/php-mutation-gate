<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Adapter\Filesystem\DeliveryDirectory;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Delivery\Stage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * `--deliver-later`, which `plan`, `survivors` and `verdict` take (ADR-0007 decision 5): the run sends nothing that
 * needs a credential and writes no store. It leaves its ledger and those payloads in its stage's delivery directory,
 * `.mutation-gate/delivery/planned`, `.mutation-gate/delivery/survivors` or `.mutation-gate/delivery/verdict`,
 * begun empty, for `deliver` to send.
 */
final readonly class DeliverLater
{
    public const string OPTION = 'deliver-later';

    private const string SAID = 'Leave what needs a credential in .mutation-gate/delivery, for mutation-gate deliver';

    /** The command, taking `--deliver-later`. */
    public static function option(Command $command): Command
    {
        return $command->addOption(self::OPTION, mode: InputOption::VALUE_NONE, description: self::SAID);
    }

    /**
     * What the flow runs with, leaving its delivery in this stage's directory, begun empty, where the options ask
     * for it; or why that directory cannot be begun.
     */
    public static function composed(
        Composed|Invalid|CannotJudge $composed,
        InputInterface $input,
        Stage $stage,
    ): Composed|Invalid|CannotJudge {
        if (! $composed instanceof Composed || $input->getOption(self::OPTION) !== true) {
            return $composed;
        }

        $delivery = DeliveryDirectory::of($composed->adapters->project, $stage);
        $begun = $delivery->begun();

        return $begun instanceof CannotJudge ? $begun : $composed->deliveringLater($delivery);
    }
}
