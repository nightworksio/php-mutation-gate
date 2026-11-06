<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine\Schema;

use function array_slice;
use function is_int;

use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Hint\Change;
use NightWorksIO\MutationGate\Core\NotGiven;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;
use PhpToken;

use function sprintf;
use function str_contains;

/**
 * A mutant's code as a schema holds it beside the original (ADR-0091): its
 * replacement printed on one line, so no line of the file moves, comments
 * left out; none where a token of it spans lines, as a heredoc does, or it
 * cannot be printed in its place.
 *
 * @internal the engine's own
 */
final readonly class OneLine
{
    /** What a removal leaves in the original's place. */
    private const string REMOVED = '';

    public static function of(Node|int $replacement, SiteKind $kind): string|NotGiven
    {
        $printed = is_int($replacement) ? self::REMOVED : self::printed($replacement, $kind);

        return $printed instanceof NotGiven ? $printed : self::joined($printed);
    }

    private static function printed(Node $replacement, SiteKind $kind): string|NotGiven
    {
        $printer = new Standard();

        return match (true) {
            $kind === SiteKind::Expression && $replacement instanceof Expr => $printer->prettyPrintExpr($replacement),
            $kind === SiteKind::Statement && $replacement instanceof Stmt => $printer->prettyPrint([$replacement]),
            $kind === SiteKind::ArrayItem && $replacement instanceof ArrayItem => self::item($printer, $replacement),
            default => NotGiven::value(),
        };
    }

    /** An array's item as it is written between the brackets. */
    private static function item(Standard $printer, ArrayItem $item): string
    {
        $array = $printer->prettyPrintExpr(new Array_([$item]));

        return Bytes::slice($array, 1, Bytes::length($array) - 2);
    }

    /** The code on one line, its comments left out; none where a token of it spans lines. */
    private static function joined(string $code): string|NotGiven
    {
        $joined = '';

        foreach (array_slice(PhpToken::tokenize(sprintf('%s%s', Change::OPENING, $code)), 1) as $token) {
            if (! $token->isIgnorable() && str_contains($token->text, "\n")) {
                return NotGiven::value();
            }

            $joined = sprintf('%s%s', $joined, $token->isIgnorable() ? ' ' : $token->text);
        }

        return $joined;
    }
}
