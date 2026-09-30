<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * Where a file report is written: the `path` of its `reports` entry, which
 * the command line hands it among its options, from the project and inside
 * it, or absolute where the command line names it.
 */
final readonly class ReportPath
{
    private const string KEY = 'path';

    private const string NO_FILE = '%s is written to a file, whose `path` the entry names.';

    private function __construct(private ProjectPath $path)
    {
    }

    public static function at(string $path): self
    {
        return new self(ProjectPath::of($path));
    }

    /** The file an entry for this report must name; where it names none, why the entry is invalid. */
    public static function ofFile(Options $options, string $report): self|Invalid
    {
        return self::from($options, '', sprintf(self::NO_FILE, $report));
    }

    /** The path an entry names, or the path it may leave out; with neither, why the entry is invalid. */
    public static function from(Options $options, string $otherwise, string $what): self|Invalid
    {
        $path = $options->text(Key::of(self::KEY));
        $named = match (true) {
            $path instanceof NotGiven => $otherwise,
            is_string($path) => $path,
            default => '',
        };

        return $named === '' ? Invalid::because(Problem::at(self::KEY, $what)) : self::at($named);
    }

    public function value(): string
    {
        return $this->path->value();
    }

    /** This path, as a directory, with a file under it. */
    public function file(string $name): self
    {
        return new self($this->path->child($name));
    }

    /** Write the file at this path, creating the directories it needs, saying where or why not. */
    public function write(string $text): Written|NotWritten
    {
        return $this->said($this->path->directory()->write($this->path->inside(), Contents::of($text)));
    }

    /**
     * Write the file at this path piece by piece, creating the directories it needs, saying where or why not.
     *
     * @param iterable<string> $pieces
     */
    public function stream(iterable $pieces): Written|NotWritten
    {
        return $this->said($this->path->directory()->stream($this->path->inside(), $pieces));
    }

    /** What the file at this path holds; nothing where there is none, or it cannot be read. */
    public function read(): string
    {
        $read = $this->path->directory()->read($this->path->inside());

        return $read instanceof Contents ? $read->text() : '';
    }

    /** Where the file was written, as this path names it, or why it was not. */
    private function said(Written|CannotJudge $written): Written|NotWritten
    {
        return $written instanceof CannotJudge ? NotWritten::because($written->why()) : Written::to($this->value());
    }
}
