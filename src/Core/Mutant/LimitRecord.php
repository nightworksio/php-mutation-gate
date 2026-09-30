<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\WrittenBytes;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * The limit that stopped a mutant, and what its unmutated code needs of it,
 * as a mutant's full record holds them: `limit` and `testSeconds` in seconds
 * for a timeout, `limitBytes` and `suiteBytes` in bytes for the memory cap.
 *
 * @internal the shape of the plan, shard result and ledger files
 *
 * @phpstan-type Written array{limit?: float, limitBytes?: int, testSeconds?: float, suiteBytes?: int}
 */
final readonly class LimitRecord
{
    private const string LIMIT = 'limit';

    private const string TEST_SECONDS = 'testSeconds';

    /** The memory cap that stopped a mutant, in bytes. */
    private const string LIMIT_BYTES = 'limitBytes';

    /** The most memory the unmutated suite's largest process held, in bytes. */
    private const string SUITE_BYTES = 'suiteBytes';

    /** @return Written */
    public static function of(Mutant $mutant): array
    {
        $limit = $mutant->limit();
        $need = $mutant->unmutatedNeed();

        return [
            ...$limit instanceof Seconds ? [self::LIMIT => $limit->seconds()] : [],
            ...$limit instanceof MemoryCap ? [self::LIMIT_BYTES => $limit->bytes()] : [],
            ...$need instanceof Seconds ? [self::TEST_SECONDS => $need->seconds()] : [],
            ...$need instanceof MemoryCap ? [self::SUITE_BYTES => $need->bytes()] : [],
        ];
    }

    /**
     * The mutant, with the limit and the need its record holds.
     *
     * @throws NotInShape
     */
    public static function onto(Mutant $mutant, Node $record): Mutant
    {
        $limit = self::measureIn($record, self::LIMIT, self::LIMIT_BYTES);
        $need = self::measureIn($record, self::TEST_SECONDS, self::SUITE_BYTES);
        $limited = $limit instanceof Unmeasured ? $mutant : $mutant->withLimit($limit);

        return $need instanceof Unmeasured ? $limited : $limited->withUnmutatedNeed($need);
    }

    /**
     * A limit, or what the unmutated code needs of one: in bytes where the
     * record counts them, in seconds where it has those, and unmeasured where
     * it has neither.
     *
     * @throws NotInShape
     */
    private static function measureIn(Node $record, string $seconds, string $bytes): Seconds|MemoryCap|Unmeasured
    {
        $counted = $record->field($bytes);
        $timed = $record->field($seconds);

        return match (true) {
            $counted->isPresent() => WrittenBytes::read($counted),
            $timed->isPresent() => Seconds::of($timed->number()),
            default => Unmeasured::duration(),
        };
    }
}
