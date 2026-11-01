<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm\Database;

use function chmod;
use function file_put_contents;
use function is_file;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\WholeFile;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

use function touch;
use function unlink;

/**
 * The test databases of one warm worker's children (ADR-0023). A worker
 * holds no connection: it keeps the baseline a child took, and hands it to
 * each child it forks, which takes it up once Pest has loaded the project's
 * autoloader and boots the gate's plugin, before any test runs.
 * - A child handed a baseline sets each counter back to the baseline's, over
 *   a connection of its own, and starts with `RefreshDatabase` told its
 *   database is migrated, so it migrates nothing, where every database then
 *   stands as the baseline does.
 * - A child handed none, or whose databases stand elsewhere, as after a test
 *   that committed, runs as a fresh process does: `RefreshDatabase` migrates,
 *   and the databases are taken as the baseline the moment it has migrated,
 *   before its first transaction, each read over a connection of its own as
 *   every later check is, and reported to the worker at once, so a child
 *   stopped at its limit still leaves it.
 * The report holds the connections' passwords, so only its owner may read it,
 * and the worker removes it once read.
 *
 * @internal
 */
final class WarmDatabase
{
    private const string BASELINE = 'baseline';

    private const int OWNER_ONLY = 0o600;

    /** @var list<self> what a worker handed the child this process is, before Pest ran in it; none elsewhere */
    private static array $handed = [];

    private function __construct(private readonly Baseline|NotGiven $baseline, private readonly string $report)
    {
    }

    /**
     * The baseline a worker keeps handed to the child this process is, before
     * Pest runs in it, with the file it reports a baseline it takes to.
     */
    public static function handed(Baseline|NotGiven $baseline, string $report): void
    {
        self::$handed = [new self($baseline, $report)];
    }

    /**
     * The test databases readied from what the worker handed this process, as
     * the gate's plugin boots, before any test runs: none where it handed
     * nothing, as in every process no worker forked.
     */
    public static function begun(): void
    {
        foreach (self::$handed as $database) {
            $database->readied();
        }

        self::$handed = [];
    }

    /**
     * The baseline a worker keeps once its child has ended: the one the child
     * took, where it took one; else the one it kept, which its next child
     * checks before it starts on it.
     */
    public static function after(Baseline|NotGiven $kept, string $report): Baseline|NotGiven
    {
        $at = WholeFile::read($report);

        if (is_file($report)) {
            unlink($report);
        }

        try {
            $taken = $at instanceof Node ? Baseline::read($at->field(self::BASELINE)) : $kept;
        } catch (NotInShape) {
            $taken = $kept;
        }

        return $taken instanceof Baseline ? $taken : $kept;
    }

    /** The databases taken as the baseline, as `RefreshDatabase` has just migrated them, and reported at once. */
    public function took(): void
    {
        $connections = Laravel::migrated() ? Laravel::connections() : NotGiven::value();
        $baseline = $connections instanceof NotGiven ? $connections : Baseline::taken($connections);

        if ($baseline instanceof Baseline) {
            touch($this->report);
            chmod($this->report, self::OWNER_ONLY);
            file_put_contents($this->report, Json::object(Member::of(self::BASELINE, $baseline->written()))->line());
        }
    }

    /** Told migrated where the databases stand as the baseline does, else watched for the first migration. */
    private function readied(): void
    {
        $holds = $this->baseline instanceof Baseline && $this->baseline->holds();

        if ($holds) {
            Laravel::markMigrated();
        }

        if (! $holds) {
            Laravel::beforeFirstTransaction($this->took(...));
        }
    }
}
