<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_flip;
use function array_key_exists;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Report;
use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;

/**
 * One entry of `reports`: always an object, with the reporter in `use`, its
 * options in `with`, and in `path` where a file report is written, which a
 * built-in reporter needs (ADR-0009).
 */
final readonly class ReportEntry implements Node
{
    /**
     * @param Section<Fields> $written
     * @param list<string>    $sending the built-in reporters that send rather than write a file, and take no path
     */
    private function __construct(private Builtins $builtins, private Section $written, private array $sending)
    {
    }

    /** @param list<string> $sending the built-in reporters that send rather than write a file, and take no path */
    public static function choosing(Builtins $builtins, array $sending): self
    {
        return new self(
            $builtins,
            Section::fields(
                Field::required('use', Text::of('a name or a class'), Effect::JudgesOrReportsOnly),
                Field::optional('path', Location::path(), Effect::JudgesOrReportsOnly),
                Field::optional('with', OpenObject::any(), Effect::JudgesOrReportsOnly),
            ),
            $sending,
        );
    }

    public function read(mixed $value, string $at): Reading
    {
        $written = $this->written->read($value, $at);
        $fields = $written->value();

        return $fields instanceof Fields ? $this->report($fields, $written, $at) : $written;
    }

    public function expected(): string
    {
        return 'an object with use and path';
    }

    public function schema(): array
    {
        return [
            'anyOf' => $this->builtins->schemas(['path' => Location::path()->schema()], ['path'], $this->sending),
        ];
    }

    public function effects(): array
    {
        return $this->builtins->effects();
    }

    private function report(Fields $fields, Reading $written, string $at): Reading
    {
        $use = $fields->string('use');
        $path = $fields->optional('path', Path::class);
        $chosen = $this->builtins->choose($use, $fields->has('with') ? Json::decode($fields->string('with')) : [], $at);
        $choice = $chosen->value();

        $sends = array_key_exists($use, array_flip($this->sending));

        return match (true) {
            ! $choice instanceof Choice => $chosen,
            $path instanceof Absent && $this->builtins->has($use) && ! $sends => Reading::refused([
                Problem::at(At::key($at, 'path'), 'expected a path, got nothing'),
            ]),
            $path instanceof Path && $sends => Reading::refused([
                Problem::at(
                    At::key($at, 'path'),
                    sprintf('expected nothing, as %s writes no file, got "%s"', $use, $path->value()),
                ),
            ]),
            default => Reading::of(Report::of($choice, $path), $written->shown()),
        };
    }
}
