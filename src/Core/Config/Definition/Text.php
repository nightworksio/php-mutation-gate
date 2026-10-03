<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

use function preg_match;
use function sprintf;

/**
 * Text that is not empty, named for what it holds: `a reason`, `a glob`; or
 * a word, text with no whitespace in it: `a group name`.
 *
 * @implements Shape<non-empty-string>
 */
final readonly class Text implements Shape
{
    /** What text holds: anything but nothing, or a word. */
    private const string ANY = '.';

    private const string WORD = '^\\S+$';

    private function __construct(private string $what, private string $pattern)
    {
    }

    public static function of(string $what): self
    {
        return new self($what, self::ANY);
    }

    /** Text this pattern matches whole, such as a name a cloud allows. */
    public static function matching(string $what, string $pattern): self
    {
        return new self($what, $pattern);
    }

    /** A word, such as a group name, which whitespace would split. */
    public static function word(string $what): self
    {
        return new self(sprintf('%s, with no whitespace', $what), self::WORD);
    }

    public function read(Node $at): Reading
    {
        $text = $at->kind() === Kind::Text ? $at->text() : '';

        return $text !== '' && preg_match(sprintf('#%s#Dsu', $this->pattern), $text) === 1
            ? Reading::of($text)
            : Reading::refused($at->mismatch($this->what));
    }

    public function expected(): string
    {
        return $this->what;
    }

    public function schema(): Json
    {
        $schema = Json::object(Member::of('type', 'string'))->with(Member::of('minLength', 1));

        return $this->pattern === self::ANY ? $schema : $schema->with(Member::of('pattern', $this->pattern));
    }

    public function effects(): array
    {
        return [];
    }
}
