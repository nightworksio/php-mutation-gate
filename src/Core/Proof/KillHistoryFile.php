<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_flip;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Order\KillHistory;

use function sprintf;

/**
 * A kill history as a file of its own holds it, `"format": 1`: the tests it
 * names and its `killers`, each as a ledger's sections hold them (ADR-0013,
 * decision 2). The plan hands each shard one beside its map, as
 * `killers.json`, and the Pest adapter hands its plugin one. A history orders
 * tests and never decides a result, so it enters no key.
 *
 * @internal the shape of a kill history file
 */
final readonly class KillHistoryFile
{
    private const int FORMAT = 1;

    private const string NAME = 'killers.json';

    /** Where the history stands in the directory a job hands it over in, beside the coverage map. */
    public static function in(Path $directory): Path
    {
        return Path::of(sprintf('%s/%s', $directory->value(), self::NAME));
    }

    public static function encode(KillHistory $history): string
    {
        $tests = KillersRecord::testsOf($history);

        return JsonText::compact([
            'format' => self::FORMAT,
            LedgerFile::TESTS => $tests,
            KillersRecord::SECTION => KillersRecord::of($history, array_flip($tests)),
        ]);
    }

    public static function decode(string $json): KillHistory|CannotJudge
    {
        try {
            return self::historyIn(Node::decode($json));
        } catch (NotInShape $refused) {
            return CannotJudge::because(sprintf('A kill history cannot be read: %s', $refused->getMessage()));
        }
    }

    /** @throws NotInShape */
    private static function historyIn(Node $file): KillHistory
    {
        if ($file->field('format')->integer() !== self::FORMAT) {
            throw NotInShape::at($file->field('format')->at(), sprintf('format %d', self::FORMAT));
        }

        $tests = [];

        foreach ($file->field(LedgerFile::TESTS)->items() as $test) {
            $tests[] = $test->text();
        }

        return KillersRecord::read($file->field(KillersRecord::SECTION), $tests);
    }
}
