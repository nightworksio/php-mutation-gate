<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_slice;
use function array_values;
use function mb_strstr;
use function mb_substr_count;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Functions;
use PhpToken;

use function sprintf;
use function str_contains;

/**
 * A runner's ignore marker wherever a comment of a PHP file holds it, each at
 * the line it is on and in the function it is in or, from a doc comment, the
 * function the comment documents. Text in a string is not a marker.
 */
final readonly class SourceMarkers
{
    /** What ends the declaration a doc comment documents, before any `function` of its own. */
    private const array ENDS = ['{', ';', '}'];

    public static function in(Path $file, Contents $source, string $marker): Markers
    {
        $tokens = array_values(PhpToken::tokenize($source->text()));
        $functions = Functions::in($source);
        $markers = Markers::none();

        foreach ($tokens as $at => $token) {
            if ($token->is([T_COMMENT, T_DOC_COMMENT]) && str_contains($token->text, $marker)) {
                $before = sprintf('%s', mb_strstr($token->text, $marker, before_needle: true));
                $line = Line::of($token->line + mb_substr_count($before, "\n"));
                $enclosing = Enclosing::of($file, $functions->around(self::spokenFor($tokens, $at, $line)));
                $markers = $markers->with(Marker::inSource($file, $line, $marker, $enclosing));
            }
        }

        return $markers;
    }

    /**
     * The line whose function a marker speaks for: that of the `function` a
     * doc comment documents, or the marker's own.
     *
     * @param list<PhpToken> $tokens
     */
    private static function spokenFor(array $tokens, int $at, Line $line): Line
    {
        foreach ($tokens[$at]->is(T_DOC_COMMENT) ? array_slice($tokens, $at) : [] as $token) {
            if ($token->is(self::ENDS)) {
                break;
            }

            if ($token->is(T_FUNCTION)) {
                return Line::of($token->line);
            }
        }

        return $line;
    }
}
