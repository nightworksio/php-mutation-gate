<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_map;

/**
 * A function or class of PHP's, or Symfony's Finder, that reads a file or
 * lists a directory without running what it reads. A test whose code names
 * one can read a source no coverage map shows it ran and no name of it
 * reaches, so its units' keys read every file the map observes (ADR-0007).
 *
 * @internal
 */
enum FileReader: string
{
    case FileGetContents = 'file_get_contents';
    case File = 'file';
    case Fopen = 'fopen';
    case Readfile = 'readfile';
    case ParseIniFile = 'parse_ini_file';
    case SimplexmlLoadFile = 'simplexml_load_file';
    case HighlightFile = 'highlight_file';
    case PhpStripWhitespace = 'php_strip_whitespace';
    case Scandir = 'scandir';
    case Opendir = 'opendir';
    case Glob = 'glob';
    case SplFileObject = 'splfileobject';
    case SplFileInfo = 'splfileinfo';
    case DirectoryIterator = 'directoryiterator';
    case RecursiveDirectoryIterator = 'recursivedirectoryiterator';
    case FilesystemIterator = 'filesystemiterator';
    case GlobIterator = 'globiterator';
    case Finder = 'finder';

    /** @return list<string> every one, by its lower-cased name */
    public static function names(): array
    {
        return array_map(static fn(self $reader): string => $reader->value, self::cases());
    }
}
