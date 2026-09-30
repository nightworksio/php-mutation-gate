<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function basename;
use function dirname;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Options;

use function sprintf;

/**
 * Where a file report is written: the `path` of its `reports` entry, which
 * the command line hands it among its options, relative to where the gate
 * runs or absolute.
 */
final readonly class ReportPath
{
    private const string KEY = 'path';

    private function __construct(private string $path)
    {
    }

    public static function at(string $path): self
    {
        return new self($path);
    }

    /** The path an entry names, or the path it may leave out; with neither, why the entry is invalid. */
    public static function from(Options $options, string $otherwise, string $what): self|Invalid
    {
        $path = Node::decode($options->json())->field(self::KEY);

        try {
            $named = $path->isPresent() ? $path->text() : $otherwise;
        } catch (NotInShape) {
            $named = '';
        }

        return $named === '' ? Invalid::because(Problem::at(self::KEY, $what)) : new self($named);
    }

    public function value(): string
    {
        return $this->path;
    }

    /** This path, as a directory, with a file under it. */
    public function file(string $name): self
    {
        return new self(sprintf('%s/%s', $this->path, $name));
    }

    /** Write the file at this path, creating the directories it needs, saying where or why not. */
    public function write(string $text): Written|NotWritten
    {
        $written = Directory::at(dirname($this->path))->write(Path::of(basename($this->path)), Contents::of($text));

        return $written instanceof CannotJudge ? NotWritten::because($written->why()) : $written;
    }

    /** What the file at this path holds; nothing where there is none, or it cannot be read. */
    public function read(): string
    {
        $read = Directory::at(dirname($this->path))->read(Path::of(basename($this->path)));

        return $read instanceof Contents ? $read->text() : '';
    }
}
