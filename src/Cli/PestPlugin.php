<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use NightWorksIO\MutationGate\Adapter\Pest\Plugin;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\LastError;
use NightWorksIO\MutationGate\Adapter\PhpUnit\IssueKillers;
use NightWorksIO\MutationGate\Core\Test\RunIssues;
use Pest\Contracts\Plugins\Bootable;
use Pest\Contracts\Plugins\HandlesArguments;
use Pest\Contracts\Plugins\Terminable;

/**
 * The Pest plugin this package lists under `extra.pest.plugins`: the Pest
 * adapter's plugin, reading the tests whose issues failed a mutant's own run
 * from PHPUnit's result as the PHPUnit runner does (ADR-0014). The reader is
 * made only as the plugin boots, after what it notes PHP had loaded.
 *
 * Pest loads this class before pest-plugin-mutate puts a mutated file in the
 * place of the original, so a mutant of this file would never run, and the
 * gate's own config leaves it out of the trees it mutates.
 *
 * @internal
 */
final readonly class PestPlugin implements Bootable, HandlesArguments, Terminable
{
    private Plugin $plugin;

    public function __construct()
    {
        $this->plugin = new Plugin(static fn(): RunIssues => IssueKillers::ofThisRun(), LastError::file(...));
    }

    public function boot(): void
    {
        $this->plugin->boot();
    }

    public function terminate(): void
    {
        $this->plugin->terminate();
    }

    /**
     * @param  array<int, string> $arguments
     * @return list<string>
     */
    public function handleArguments(array $arguments): array
    {
        return $this->plugin->handleArguments($arguments);
    }
}
