<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_filter;
use function array_find;
use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\EntryPath;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Report;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
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
    /** @param Section<Report> $object */
    private function __construct(private Builtins $builtins, private Section $object)
    {
    }

    public static function choosing(Builtins $builtins, PathOrigin $origin): self
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
        return Json::object(
            Member::of(
                'anyOf',
                Json::items(...$this->builtins->schemas(
                    Json::object(
                        Member::of(
                            'path',
                            Json::object(Member::of('type', 'string'))->with(Member::of('minLength', 1)),
                        ),
                    ),
                    ['path'],
                    $this->named(EntryPath::Refused),
                    $this->named(EntryPath::Optional),
                )),
            ),
        );
    }

    public function effects(): array
    {
        return $this->builtins->effects();
    }

    private static function report(Builtins $builtins, Node $at, string $use, Path|Absent $path): Report|Invalid
    {
        $choice = Adapter::chosen($builtins->choose($use, $at->field('with')));
        $builtin = array_find(
            BuiltinReporter::cases(),
            static fn(BuiltinReporter $case): bool => $case->value === $use,
        );
        $needs = $builtin instanceof BuiltinReporter ? $builtin->entryPath() : EntryPath::Optional;

        return match (true) {
            ! $choice instanceof Choice => $choice,
            $path instanceof Absent && $needs === EntryPath::Required => Invalid::because(
                $at->field('path')->mismatch('a path'),
            ),
            $path instanceof Path && $needs === EntryPath::Refused => Invalid::because(Problem::at(
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

    /** @return list<string> the built-in reporters whose entries name a path so */
    private function named(EntryPath $entryPath): array
    {
        return array_values(array_map(
            static fn(BuiltinReporter $reporter): string => $reporter->value,
            array_filter(
                BuiltinReporter::cases(),
                static fn(BuiltinReporter $reporter): bool => $reporter->entryPath() === $entryPath,
            ),
        ));
    }
}
