<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use NightWorksIO\MutationGate\Core\Config\OutbreakServe;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProgramArguments;

use function sprintf;

/**
 * The command lines that start the `outbreak` binary as a process of its own
 * rather than for one answer: `outbreak serve` before the PHP daemon
 * (ADR-0133), and `outbreak lsp` for an editor, in a project at a root.
 *
 * @internal
 */
final readonly class BinaryLines
{
    /** The option that starts `outbreak serve` in a process of its own. */
    private const string DETACH = '--detach';

    /** The option `outbreak serve` takes the seconds between snapshots with. */
    private const string SNAPSHOT_EVERY = '--snapshot-every';

    /** The option `outbreak serve` takes the seconds between syncs of its journal with. */
    private const string SYNC_EVERY = '--sync-every';

    /** The option `outbreak serve` takes the seconds the changes it watches settle for with. */
    private const string DEBOUNCE = '--debounce';

    /** The option `outbreak lsp` takes the gate's script with. */
    private const string GATE = '--gate';

    /** The option `outbreak lsp` takes the PHP that runs the gate with. */
    private const string PHP = '--php';

    /** The option `outbreak lsp` takes how the editor shows a survivor with. */
    private const string SEVERITY = '--severity';

    public function __construct(
        private string|NotGiven $binary,
        private string $root,
        private OutbreakServe $serve,
    ) {
    }

    /**
     * What goes before the PHP daemon's command line of `serve` (ADR-0078)
     * so `outbreak serve` starts it as its child in a process group of its
     * own (ADR-0133), launched as the PHP daemon would be: where a binary is
     * in use and `outbreak.serve.enabled` is on; the launch alone otherwise.
     *
     * @param list<string> $launch what the daemon is launched under, such as its priority
     *
     * @return list<string>
     */
    public function serving(array $launch): array
    {
        return $this->binary instanceof NotGiven || ! $this->serve->enabled() ? $launch : [
            ...$launch,
            $this->binary,
            ...Command::Serve->words(),
            $this->root,
            self::DETACH,
            self::SNAPSHOT_EVERY,
            sprintf('%F', $this->serve->snapshotEvery()->seconds()),
            self::SYNC_EVERY,
            sprintf('%F', $this->serve->syncEvery()->seconds()),
            self::DEBOUNCE,
            sprintf('%F', $this->serve->debounce()->seconds()),
            ProgramArguments::DOUBLE_DASH,
        ];
    }

    /**
     * The command line of `outbreak lsp` (ADR-0133 decision 10), serving an
     * editor the survivors this gate's script, run by this PHP, tells of in
     * `watch` with these options, shown at this severity; none where no
     * binary is in use.
     *
     * @param list<string> $watching
     *
     * @return list<string>|NotGiven
     */
    public function editing(string $script, string $php, string $severity, array $watching): array|NotGiven
    {
        return $this->binary instanceof NotGiven ? NotGiven::value() : [
            $this->binary,
            ...Command::Lsp->words(),
            self::GATE,
            $script,
            self::PHP,
            $php,
            self::SEVERITY,
            $severity,
            ProgramArguments::DOUBLE_DASH,
            ...$watching,
        ];
    }
}
