<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_flip;
use function array_key_exists;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Origin;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Report;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * A `reports` entry (ADR-0009): the reporter, where it writes its file, and
 * its options. A built-in reporter that writes a file needs a path, and one
 * that sends takes none.
 *
 * @implements Shape<Report>
 */
final readonly class ReportEntry implements Shape
{
    /** The built-in reporters that send rather than write a file, and take no path (ADR-0016). */
    private const array SENDING = ['slack', 'discord', 'webhook', 'otlp'];

    /** @param Section<Report> $object */
    private function __construct(private Builtins $builtins, private Section $object)
    {
    }

    public static function choosing(Builtins $builtins, Origin $origin): self
    {
        $judges = Effect::JudgesOrReportsOnly;
        $use = Field::required('use', Text::of('a name or a class'), $judges);
        $path = Field::optional('path', Location::path($origin), $judges);
        $with = Field::optional('with', OpenObject::any(), $judges);

        return new self(
            $builtins,
            Section::of(
                static function (Node $at) use ($builtins, $use, $path, $with): Report|Invalid {
                    $named = $use->read($at);
                    $where = $path->read($at);

                    return Reading::built(
                        static fn(): Report|Invalid => self::report($builtins, $at, $named->must(), $where->value()),
                        $named,
                        $where,
                        $with->read($at),
                    );
                },
                $use,
                $path,
                $with,
            ),
        );
    }

    public function read(Node $at): Reading
    {
        return $this->object->read($at);
    }

    public function expected(): string
    {
        return 'an object with use and path';
    }

    public function schema(): Json
    {
        return Json::object()->with(
            'anyOf',
            Json::items($this->builtins->schemas(
                Json::object()->with('path', Json::object()->with('type', 'string')->with('minLength', 1)),
                ['path'],
                self::SENDING,
            )),
        );
    }

    public function effects(): array
    {
        return $this->builtins->effects();
    }

    private static function report(Builtins $builtins, Node $at, string $use, Path|Absent $path): Report|Invalid
    {
        $choice = Adapter::chosen($builtins->choose($use, $at->field('with')));
        $sends = array_key_exists($use, array_flip(self::SENDING));

        return match (true) {
            ! $choice instanceof Choice => $choice,
            $path instanceof Absent && $builtins->has($use) && ! $sends => Invalid::because(
                $at->field('path')->mismatch('a path'),
            ),
            $path instanceof Path && $sends => Invalid::because(Problem::at(
                $at->field('path')->at(),
                sprintf(
                    'expected nothing, as %s writes no file, got "%s"',
                    $use,
                    $at->field('path')->text(),
                ),
            )),
            default => Report::of($choice, $path),
        };
    }
}
