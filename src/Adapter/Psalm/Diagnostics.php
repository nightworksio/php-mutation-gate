<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Psalm;

use function array_map;
use function explode;
use function implode;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\FindingFiles;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;

use function rawurldecode;
use function rawurlencode;
use function sprintf;
use function str_starts_with;

/**
 * What Psalm's language server publishes about one file, as
 * `textDocument/publishDiagnostics` carries it: each diagnostic with its
 * issue type in `data.type`, which the server sends where the client says it
 * reads it, and its message, which starts with the type in brackets, as the
 * command line's report does not. An `Error` is an error, and every lower
 * severity is lesser.
 */
final readonly class Diagnostics
{
    /** The protocol's severity of an error. */
    private const int ERROR = 1;

    /** How a file's URI starts. */
    private const string FILE_URI = 'file://';

    /** How the server starts a diagnostic's message: with its type in brackets. */
    private const string TYPED = '[%s] ';

    /** The findings published for a file, each in it. */
    public static function of(Node $published, FindingFiles $files): Findings
    {
        $file = $files->of(self::pathOf(Lenient::text($published->field('uri'))));
        $findings = [];

        foreach (Lenient::items($published->field('diagnostics')) as $diagnostic) {
            $type = Lenient::text($diagnostic->field('data')->field('type'));
            $message = self::untyped(Lenient::text($diagnostic->field('message')), $type);
            $findings[] = Lenient::integer($diagnostic->field('severity')) === self::ERROR
                ? Finding::error($file, $type, $message)
                : Finding::lesser($file, $type, $message);
        }

        return Findings::of(...$findings);
    }

    /** The URI the server knows a file by, from its absolute path: each segment encoded, as the server decodes it. */
    public static function uriOf(string $path): string
    {
        return sprintf('%s%s', self::FILE_URI, implode('/', array_map(rawurlencode(...), explode('/', $path))));
    }

    /** The path a file's URI names. */
    private static function pathOf(string $uri): string
    {
        return rawurldecode(str_starts_with($uri, self::FILE_URI) ? mb_substr($uri, mb_strlen(self::FILE_URI)) : $uri);
    }

    /** A message without the type the server puts before it, so it reads as the command line's report says it. */
    private static function untyped(string $message, string $type): string
    {
        $prefix = sprintf(self::TYPED, $type);

        return str_starts_with($message, $prefix) ? mb_substr($message, mb_strlen($prefix)) : $message;
    }
}
