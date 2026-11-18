<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function is_string;

use NightWorksIO\MutationGate\Core\Config\FirstFork;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\SlowdownCarry;
use NightWorksIO\MutationGate\Core\Config\SplitStream;
use NightWorksIO\MutationGate\Core\Config\StreamChildren;
use NightWorksIO\MutationGate\Core\Config\StreamJudging;
use NightWorksIO\MutationGate\Core\Config\StreamWork;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * The `splitStream` option of the Pest runner, read into what a patched run
 * asks of its split streams (ADR-0117); every key at its default where the
 * option leaves it out.
 *
 * @internal
 */
final readonly class StreamOptions
{
    /** `splitStream`: whether, and how, a patched run judges its mutants in split streams (ADR-0117). */
    public static function read(Options $options): StreamSettings|Problem
    {
        $split = $options->object(Key::of(SplitStream::SECTION));

        if (! $split instanceof Options) {
            return $split instanceof Problem ? $split : StreamSettings::off();
        }

        $enabled = $split->flag(Key::of(SplitStream::ENABLED));
        $children = self::childrenOf($split);
        $changeCheck = $split->flag(Key::of(SplitStream::CHANGE_CHECK));
        $killersFirst = $split->flag(Key::of(SplitStream::KILLERS_FIRST));
        $killerStreams = $split->flag(Key::of(SplitStream::KILLER_STREAMS));
        $bootOnce = $split->flag(Key::of(SplitStream::BOOT_ONCE));
        $reconnect = $split->flag(Key::of(SplitStream::RECONNECT));
        $fallbackOwnRun = $split->flag(Key::of(SplitStream::FALLBACK_OWN_RUN));
        $coveringOnly = $split->flag(Key::of(SplitStream::COVERING_TESTS_ONLY));
        $deadline = $split->flag(Key::of(StreamWork::PARENT_DEADLINE));
        $backoff = $split->number(Key::of(StreamWork::DEADLINE_BACKOFF));
        $carry = self::carryOf($split);
        $firstFork = self::firstForkOf($split);
        $parts = self::partsOf($split);
        $standard = SplitStream::of();
        $judging = self::judgingOf($split, $standard->judging());

        return match (true) {
            $enabled instanceof Problem => $enabled,
            $children instanceof Problem => $children,
            $changeCheck instanceof Problem => $changeCheck,
            $killersFirst instanceof Problem => $killersFirst,
            $killerStreams instanceof Problem => $killerStreams,
            $bootOnce instanceof Problem => $bootOnce,
            $reconnect instanceof Problem => $reconnect,
            $judging instanceof Problem => $judging,
            $fallbackOwnRun instanceof Problem => $fallbackOwnRun,
            $coveringOnly instanceof Problem => $coveringOnly,
            $deadline instanceof Problem => $deadline,
            $backoff instanceof Problem => $backoff,
            $carry instanceof Problem => $carry,
            $firstFork instanceof Problem => $firstFork,
            $parts instanceof Problem => $parts,
            default => StreamSettings::of(
                $enabled === true,
                $children instanceof NotGiven ? $standard->children() : $children,
                $changeCheck !== false,
                $killersFirst !== false,
                $judging,
                $fallbackOwnRun !== false,
                $coveringOnly !== false,
                $killerStreams === true,
                $bootOnce === true,
                $reconnect !== false,
                new StreamPace(
                    $deadline !== false,
                    $backoff instanceof NotGiven ? $standard->work()->deadlineBackoff()->times() : $backoff,
                    $carry,
                    $firstFork,
                    $parts,
                ),
            ),
        };
    }

    /** `splitStream.children`: a count, or `cores` (ADR-0173). */
    private static function childrenOf(Options $split): int|StreamChildren|NotGiven|Problem
    {
        $key = Key::of(SplitStream::CHILDREN);
        $text = $split->text($key);
        $cores = is_string($text) ? StreamChildren::tryFrom($text) : null;

        return $cores ?? $split->integer($key);
    }

    /** `splitStream.judging`: `whole` or `parted`, this one where it names neither (ADR-0174). */
    private static function judgingOf(Options $split, StreamJudging $standard): StreamJudging|Problem
    {
        $named = $split->text(Key::of(SplitStream::JUDGING));

        return match (true) {
            $named instanceof Problem => $named,
            $named instanceof NotGiven => $standard,
            default => StreamJudging::tryFrom($named) ?? Problem::mismatch(
                sprintf('%s.%s', SplitStream::SECTION, SplitStream::JUDGING),
                'one of its values',
                sprintf('"%s"', $named),
            ),
        };
    }

    /** `splitStream.firstFork`: where a first pass forks a mutant the ledger names no killer of (ADR-0187). */
    private static function firstForkOf(Options $split): FirstFork|Problem
    {
        $named = $split->text(Key::of(StreamWork::FIRST_FORK));

        return match (true) {
            $named instanceof Problem => $named,
            $named instanceof NotGiven => SplitStream::of()->work()->firstFork(),
            default => FirstFork::tryFrom($named) ?? Problem::mismatch(
                sprintf('%s.%s', SplitStream::SECTION, StreamWork::FIRST_FORK),
                'one of its values',
                sprintf('"%s"', $named),
            ),
        };
    }

    /** `splitStream.partsPerProcess`: how many parts a pass cuts for each stream it runs at once (ADR-0188). */
    private static function partsOf(Options $split): int|Problem
    {
        $parts = $split->integer(Key::of(StreamWork::PARTS_PER_PROCESS));

        return $parts instanceof NotGiven ? SplitStream::of()->work()->partsPerProcess() : $parts;
    }

    /** `splitStream.slowdownCarry`: where the slowdown a pass's streams measured carries on to (ADR-0184). */
    private static function carryOf(Options $split): SlowdownCarry|Problem
    {
        $named = $split->text(Key::of(StreamWork::SLOWDOWN_CARRY));

        return match (true) {
            $named instanceof Problem => $named,
            $named instanceof NotGiven => SplitStream::of()->work()->slowdownCarry(),
            default => SlowdownCarry::tryFrom($named) ?? Problem::mismatch(
                sprintf('%s.%s', SplitStream::SECTION, StreamWork::SLOWDOWN_CARRY),
                'one of its values',
                sprintf('"%s"', $named),
            ),
        };
    }
}
