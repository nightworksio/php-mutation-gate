<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function implode;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Writing;

use function sprintf;

/**
 * The ledger `mutators` and `config:suggest` read (ADR-0076): the
 * `ledger.json.gz` that `--ledger` names, from the project or the file
 * system's root, within the limits a run reads to, or else the default branch's,
 * read through the proof store, which a ledger it cannot read refuses;
 * writing nothing.
 *
 * @internal
 */
final readonly class TalliedLedger
{
    private const string MISSING = 'No ledger is at %s.';

    private const string UNREAD = 'The ledger at %s cannot be read: %s';

    public static function read(Composed $composed, Path|NotGiven $file): Ledger|CannotJudge
    {
        return $file instanceof Path ? self::file($composed, $file) : self::defaultBranch($composed);
    }

    private static function defaultBranch(Composed $composed): Ledger|CannotJudge
    {
        $adapters = $composed->adapters;
        $standing = Standing::of($adapters->ci, $adapters->repository, $composed->settings->ci()->defaultBranch());

        if ($standing instanceof CannotJudge) {
            return $standing;
        }

        $ledgers = Ledgers::read($adapters->proofs, $standing, Writing::Never);
        $unread = [];

        foreach ($ledgers->unread() as $warning) {
            $unread[] = $warning->text();
        }

        return $unread === [] ? $ledgers->defaultBranch() : CannotJudge::because(implode(' ', $unread));
    }

    private static function file(Composed $composed, Path $file): Ledger|CannotJudge
    {
        $limits = LedgerLimits::standard();
        $directory = $file->isAbsolute() ? Directory::at($file->base()) : $composed->adapters->project;
        $bytes = $directory->readAtMost($file->fromBase(), $limits->packed());
        $read = match (true) {
            $bytes instanceof Contents => LedgerFile::read($bytes->text(), $limits),
            $bytes instanceof TooLarge => CannotJudge::because($limits->pastPacked()),
            default => $bytes,
        };

        return match (true) {
            $read instanceof Ledger => $read,
            $read instanceof Missing => CannotJudge::because(sprintf(self::MISSING, $file->value())),
            default => CannotJudge::because(sprintf(self::UNREAD, $file->value(), $read->why())),
        };
    }
}
