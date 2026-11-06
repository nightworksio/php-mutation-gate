<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hint;

use function is_string;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Php\Tokens;

use function preg_match;
use function sprintf;

/**
 * The attempts for a survivor that changed a whole statement (ADR-0101): a
 * class constant or backed enum case's value, which a test asserts by its
 * name (ADR-0037); a returned literal, which a test expects the call to
 * return; and a removed call on a collaborator, which a test double that
 * expects the call notices.
 *
 * @internal
 */
final readonly class Statements
{
    private const string CONSTANT
        = '/^(?:(?:public|protected|private|final)\s+)*const\s+(?:\??\w+\s+)?(\w+)\s*=\s*(.+?)\s*;$/u';

    private const string CASE = '/^case\s+(\w+)\s*=\s*(.+?)\s*;$/u';

    private const string RETURNED = '/^return\s+(.+?)\s*;$/u';

    private const string ON_PROPERTY = '/^\$this->(\w+)->(\w+)\s*\(/u';

    private const string ON_VARIABLE = '/^\$(\w+)->(\w+)\s*\(/u';

    private const string ASSERTED = 'assert `%s` is `%s`.';

    public static function constant(Change $change, Mutant $mutant, Contents|Missing $source): Attempt|NotGiven
    {
        $removed = $change->removed();
        $class = $source instanceof Contents ? self::classAt($source, $mutant->location()->start()->number()) : '';

        if ($class !== '' && preg_match(self::CONSTANT, $removed, $constant) === 1) {
            return Attempt::of(sprintf(self::ASSERTED, sprintf('%s::%s', $class, $constant[1]), $constant[2]));
        }

        if ($class !== '' && preg_match(self::CASE, $removed, $case) === 1) {
            return Attempt::of(sprintf(self::ASSERTED, sprintf('%s::%s->value', $class, $case[1]), $case[2]));
        }

        return NotGiven::value();
    }

    public static function returned(Change $change, Enclosing|Nameless $function): Attempt|NotGiven
    {
        $literal = preg_match(self::RETURNED, $change->removed(), $returned) === 1
            ? Values::literal($returned[1])
            : NotGiven::value();

        return $function instanceof Enclosing && is_string($literal)
            ? Attempt::of(sprintf('expect `%s` to return `%s`.', $function->call(), $literal))
            : NotGiven::value();
    }

    public static function removed(Change $change, Enclosing|Nameless $function): Attempt|NotGiven
    {
        $removed = $change->removed();
        $where = $function instanceof Enclosing ? sprintf(', then call `%s()`', $function->name()) : '';

        return match (true) {
            preg_match(self::ON_PROPERTY, $removed, $called) === 1 => Attempt::of(sprintf(
                'replace `$this->%s` with a test double that expects `%s()`%s.',
                $called[1],
                $called[2],
                $where,
            )),
            preg_match(self::ON_VARIABLE, $removed, $called) === 1 => Attempt::of(sprintf(
                'pass a test double as `$%s` that expects `%s()`%s.',
                $called[1],
                $called[2],
                $where,
            )),
            default => NotGiven::value(),
        };
    }

    /**
     * The short name of the last class-like declared on or before a line, whose
     * constants and cases a test reads by that name; nothing where none is, or
     * it is a trait, whose constants no test reads by its name.
     */
    private static function classAt(Contents $source, int $line): string
    {
        $tokens = Tokens::in($source);
        $class = '';

        foreach ($tokens->indicesOf(...Tokens::CLASS_LIKE) as $declaring) {
            $named = $tokens->is($declaring + 1, T_STRING) && $tokens->line($declaring) <= $line;
            $readable = $tokens->is($declaring, T_TRAIT) ? '' : $tokens->text($declaring + 1);
            $class = $named ? $readable : $class;
        }

        return $class;
    }
}
