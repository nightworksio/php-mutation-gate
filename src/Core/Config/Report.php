<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function implode;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

use function sprintf;

/** A `reports` entry (ADR-0009): a reporter, and where it writes its file; a reporter that sends has none. */
final readonly class Report
{
    /** The built-in reporters the builder has a method of its own for. */
    private const array NAMED = [
        BuiltinReporter::Json->value,
        BuiltinReporter::JUnit->value,
        BuiltinReporter::Sarif->value,
        BuiltinReporter::Html->value,
    ];

    private function __construct(private Choice $reporter, private Path|Absent $path)
    {
    }

    public static function of(Choice $reporter, Path|Absent $path): self
    {
        return new self($reporter, $path);
    }

    public function reporter(): Choice
    {
        return $this->reporter;
    }

    /** Where the report is written; a reporter that writes no file has none. */
    public function path(): Path|Absent
    {
        return $this->path;
    }

    /**
     * The variables this entry's alert channel reads its URL and secret from, as its options name them; none for
     * any other reporter.
     */
    public function secrets(): Withheld
    {
        $use = $this->reporter->use();
        $builtin = $use instanceof Name ? BuiltinReporter::tryFrom($use->value()) : null;

        return $builtin instanceof BuiltinReporter && $builtin->alerts()
            ? Withheld::of(...AlertOption::named($this->reporter->options()))
            : Withheld::nothing();
    }

    /** This entry as a config at this origin writes it. */
    public function written(PathOrigin $origin): Json
    {
        $chosen = $this->reporter->written();
        $written = Json::object(Member::of('use', $this->reporter->use()->value()));
        $written = $this->path instanceof Path
            ? $written->with(Member::of('path', $origin->written($this->path)))
            : $written;

        return $chosen instanceof Json
            ? $written->with(Member::of('with', $this->reporter->options()->written()))
            : $written;
    }

    /** This entry as the builder's `Report` writes it. */
    public function php(PathOrigin $origin): string
    {
        $named = PhpCalls::chosen($this->reporter, AdapterBuilder::Report, ...self::NAMED);
        $path = $this->path instanceof Path ? [PhpCalls::literal($origin->written($this->path))] : [];

        return $path !== [] && $named === sprintf('Report::%s()', $this->reporter->use()->value())
            ? sprintf('Report::%s(%s)', $this->reporter->use()->value(), $path[0])
            : sprintf(
                'Report::%s(%s)',
                $path === [] ? 'uses' : 'writing',
                implode(', ', [
                    PhpCalls::literal($this->reporter->use()->value()),
                    ...$path,
                    ...PhpOptions::of($this->reporter->options()->written()),
                ]),
            );
    }
}
