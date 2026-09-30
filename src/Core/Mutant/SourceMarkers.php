<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_find;
use function array_find_key;
use function array_slice;
use function array_values;
use function count;
use function mb_strpos;
use function mb_substr;
use function mb_substr_count;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Functions;
use PhpToken;

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
            $offset = $token->is([T_COMMENT, T_DOC_COMMENT]) ? mb_strpos($token->text, $marker) : false;

            if ($offset !== false) {
                $line = Line::of($token->line + mb_substr_count(mb_substr($token->text, 0, $offset), "\n"));
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
        $after = $tokens[$at]->is(T_DOC_COMMENT) ? array_slice($tokens, $at + 1) : [];
        $ends = array_find_key($after, static fn(PhpToken $token): bool => $token->is(self::ENDS));
        $function = array_find(
            array_slice($after, 0, $ends ?? count($after)),
            static fn(PhpToken $token): bool => $token->is(T_FUNCTION),
        );

        return $function instanceof PhpToken ? Line::of($function->line) : $line;
    }
}
