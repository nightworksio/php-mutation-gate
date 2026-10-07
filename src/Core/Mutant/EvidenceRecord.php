<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * A kill's evidence as a shard result writes it in its mutant's record
 * (ADR-0014, decision 16): `prefix`, with `at`, the position its run's first
 * failing test held, from one, and `key`, twelve hex digits, where the
 * runner gave one; and `ended`, with `code`, `signalled` where the runner
 * read them, and `tail`, the last 2 KiB its process printed. A record of an
 * earlier gate holds neither, and reads as no evidence.
 *
 * @internal the shape of a shard result's mutant record
 *
 * @phpstan-type Written array{
 *     prefix?: array{at: int, key?: string},
 *     ended?: array{code?: int, signalled?: bool, tail: string},
 * }
 */
final readonly class EvidenceRecord
{
    private const string PREFIX = 'prefix';

    private const string AT = 'at';

    private const string KEY = 'key';

    private const string ENDED = 'ended';

    private const string CODE = 'code';

    private const string SIGNALLED = 'signalled';

    private const string TAIL = 'tail';

    /** @return Written */
    public static function of(Evidence $evidence): array
    {
        $prefix = $evidence->prefix();
        $ended = $evidence->ended();

        return [
            ...$prefix instanceof Prefix ? [self::PREFIX => self::prefix($prefix)] : [],
            ...$ended instanceof Ended ? [self::ENDED => self::ended($ended)] : [],
        ];
    }

    /**
     * The evidence a mutant's record holds, none where it holds none.
     *
     * @throws NotInShape
     */
    public static function read(Node $record): Evidence
    {
        $prefix = $record->field(self::PREFIX);
        $ended = $record->field(self::ENDED);
        $evidence = $prefix->isPresent() ? Evidence::none()->withPrefix(self::prefixIn($prefix)) : Evidence::none();

        return $ended->isPresent() ? $evidence->withEnded(self::endedIn($ended)) : $evidence;
    }

    /** @return array{at: int, key?: string} */
    private static function prefix(Prefix $prefix): array
    {
        $key = $prefix->key();

        return [self::AT => $prefix->position(), ...$key instanceof NotGiven ? [] : [self::KEY => $key]];
    }

    /** @return array{code?: int, signalled?: bool, tail: string} */
    private static function ended(Ended $ended): array
    {
        $code = $ended->code();
        $signalled = $ended->signalled();

        return [
            ...$code instanceof NotGiven ? [] : [self::CODE => $code],
            ...$signalled instanceof NotGiven ? [] : [self::SIGNALLED => $signalled],
            self::TAIL => $ended->tail(),
        ];
    }

    /** @throws NotInShape */
    private static function prefixIn(Node $prefix): Prefix
    {
        $at = $prefix->field(self::AT);
        $key = $prefix->field(self::KEY);
        $position = $at->integer();
        $keyed = $key->isPresent() ? $key->text() : NotGiven::value();

        if ($position < 1) {
            throw NotInShape::at($at->at(), 'a position, which counts from one');
        }

        $read = Prefix::read($position, $keyed);

        return $read instanceof Prefix ? $read : throw NotInShape::at($key->at(), 'twelve lowercase hex digits');
    }

    /** @throws NotInShape */
    private static function endedIn(Node $ended): Ended
    {
        $code = $ended->field(self::CODE);
        $signalled = $ended->field(self::SIGNALLED);

        return Ended::of(
            $code->isPresent() ? $code->integer() : NotGiven::value(),
            $signalled->isPresent() ? $signalled->boolean() : NotGiven::value(),
            $ended->field(self::TAIL)->text(),
        );
    }
}
