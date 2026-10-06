<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function rename;
use function sprintf;

/**
 * How a warm worker's child ended, as the worker writes it beside the run's
 * output once the child is gone: the code it exited with, the signal that
 * ended it, or that it was stopped at its limit or its silence limit; and
 * how long it ran.
 */
final readonly class End
{
    private const string WRITING = '%s.writing';

    private const string SILENCED = 'silenced';

    private function __construct(private Json $record)
    {
    }

    public static function exited(int $code, float $seconds, Printed $printed): self
    {
        return new self(
            Json::object(Member::of('code', $code), Member::of('seconds', $seconds), ...$printed->members()),
        );
    }

    public static function signalled(int $signal, float $seconds, Printed $printed): self
    {
        return new self(
            Json::object(Member::of('signal', $signal), Member::of('seconds', $seconds), ...$printed->members()),
        );
    }

    public static function stopped(float $seconds, Printed $printed): self
    {
        return new self(
            Json::object(Member::of('stopped', value: true), Member::of('seconds', $seconds), ...$printed->members()),
        );
    }

    /** A child stopped where no test of it started or ended for its silence limit. */
    public static function silenced(float $seconds, Printed $printed): self
    {
        return new self(Json::object(
            Member::of(self::SILENCED, value: true),
            Member::of('seconds', $seconds),
            ...$printed->members(),
        ));
    }

    /** Written whole, under its name only once it is all there. */
    public function writtenTo(string $file): void
    {
        $writing = sprintf(self::WRITING, $file);
        file_put_contents($writing, $this->record->line());
        rename($writing, $file);
    }

    /**
     * The run at a position of a workplace, as it ended with what it printed
     * on both of its streams; or nothing, where it never ended there.
     */
    public static function ranIn(Workplace $workplace, int $at): Ran|NotGiven
    {
        $file = $workplace->end($at);
        $text = is_file($file) ? file_get_contents($file) : false;

        try {
            return is_string($text) ? self::ran(Node::decode($text), $workplace) : NotGiven::value();
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
