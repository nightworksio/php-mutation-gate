<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_map;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Commit;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Time\Instant;

/**
 * A default branch's newest judged run as the ledger file holds it, under
 * `judged` (ADR-0141): `commit`, the full id of the commit it judged,
 * `check`, the check-run it reported under, `at`, when it ended, and `open`,
 * the paths of the units it left open. A record that is not so shaped reads
 * as none, as a ledger an earlier gate wrote does, and the rest stands.
 *
 * @internal the shape of the ledger file
 *
 * @phpstan-type Written array{commit: string, check: string, at: string, open: list<string>}
 */
final readonly class JudgedRecord
{
    public const string SECTION = 'judged';

    private const string COMMIT = 'commit';

    private const string CHECK = 'check';

    private const string AT = ProofRecord::AT;

    private const string OPEN = 'open';

    private const string NONE = 'The ledger holds no judged run it can read.';

    /** @return Written */
    public static function of(Judged $judged): array
    {
        return [
            self::COMMIT => $judged->commit()->name(),
            self::CHECK => $judged->check(),
            self::AT => $judged->at()->value(),
            self::OPEN => array_map(static fn(Path $path): string => $path->value(), [...$judged->open()]),
        ];
    }

    /** The judged run a ledger file's section holds, or none where it holds none it can read. */
    public static function read(Node $section): Judged|CannotTell
    {
        try {
            $commit = Commit::parse($section->field(self::COMMIT)->text());
            $at = Instant::parse($section->field(self::AT)->text());
            $open = array_map(
                static fn(Node $path): Path => Path::of($path->text()),
                $section->field(self::OPEN)->items(),
            );

            return match (true) {
                ! $commit instanceof Commit => CannotTell::because(self::NONE),
                ! $at instanceof Instant => CannotTell::because(self::NONE),
                default => Judged::of(
                    $commit->revision(),
                    $section->field(self::CHECK)->text(),
                    $at,
                    Paths::of(...$open),
                ),
            };
        } catch (NotInShape) {
            return CannotTell::because(self::NONE);
        }
    }
}
