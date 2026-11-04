<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native\Warm;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MaxRss;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Staged;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function rename;

/**
 * How a warm worker's child ended, as the worker writes it beside the run's
 * output once the child is gone: the code it exited with, the signal that
 * ended it, or that it was stopped at its limit or its silence limit; how
 * long it ran; and the most memory it held, where it ended of itself.
 *
 * @internal
 */
final readonly class End
{
    private const string SILENCED = 'silenced';

    /** @param list<Member> $record */
    private function __construct(private array $record)
    {
    }

    public static function exited(int $code, float $seconds, Printed $printed): self
    {
        return new self([Member::of('code', $code), Member::of('seconds', $seconds), ...$printed->members()]);
    }

    public static function signalled(int $signal, float $seconds, Printed $printed): self
    {
        return new self([Member::of('signal', $signal), Member::of('seconds', $seconds), ...$printed->members()]);
    }

    public static function stopped(float $seconds, Printed $printed): self
    {
        return new self([Member::of('stopped', value: true), Member::of('seconds', $seconds), ...$printed->members()]);
    }

    /** A child stopped where no test of it started or ended for its silence limit. */
    public static function silenced(float $seconds, Printed $printed): self
    {
        return new self([
            Member::of(self::SILENCED, value: true),
            Member::of('seconds', $seconds),
            ...$printed->members(),
        ]);
    }

    /** The same end, the child having held at most this `ru_maxrss`, where 0 says nothing. */
    public function peaking(int $maxRss): self
    {
        return new self([...$this->record, Member::of(MaxRss::RECORDED, $maxRss)]);
    }

    /** Written whole, under its name only once it is all there. */
    public function writtenTo(string $file): void
    {
        $writing = Staged::beside($file);
        file_put_contents($writing, Json::object(...$this->record)->line());
        rename($writing, $file);
    }

    /**
     * The run at a position of a workplace, as it ended with what it printed
     * on both of its streams; or nothing, where it never ended there.
     */
    public static function ranIn(Workplace $workplace, int $at): Ran|NotGiven
    {
        $file = $workplace->end($at);
        $text = is_file($file) ? file_get_contents($file) : NotGiven::value();

        try {
            return is_string($text) ? self::ran(Node::decode($text), $workplace) : NotGiven::value();
        } catch (NotInShape) {
            return NotGiven::value();
        }
    }

    /**
     * The most memory the run at a position of a workplace held, as its
     * child's end says; none where it says none, as for a child stopped.
     */
    public static function peakIn(Workplace $workplace, int $at): MemoryCap|NotGiven
    {
        $file = $workplace->end($at);
        $text = is_file($file) ? file_get_contents($file) : NotGiven::value();

        try {
            $peak = is_string($text) ? Node::decode($text)->field(MaxRss::RECORDED) : NotGiven::value();

            return $peak instanceof Node && $peak->isPresent()
                ? MaxRss::peak($peak->integer(), PHP_OS_FAMILY)
                : NotGiven::value();
        } catch (NotInShape) {
            return NotGiven::value();
        }
    }

    private static function ran(Node $record, Workplace $workplace): Ran
    {
        $output = Printed::read($record)->text($workplace);
        $code = $record->field('code');
        $signal = $record->field('signal');
        $ended = match (true) {
            $code->isPresent() => Ran::exited($code->integer(), $output),
            $signal->isPresent() => Ran::signalled($signal->integer(), $output),
            $record->field(self::SILENCED)->isPresent() => Ran::silenced($output),
            default => Ran::stopped($output),
        };

        return $ended->took(Seconds::of($record->field('seconds')->number()));
    }
}
