<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use Iterator;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * The options a config writes beside an adapter, under `with`, read one key
 * at a time as the type it should hold. Each answers its value, `NotGiven`
 * where the key is left out, or the `Problem` at its path where it holds
 * something else. A built-in adapter's options arrive with every default
 * the definition gives them filled in.
 *
 * @implements IteratorAggregate<int, Key>
 */
final readonly class Options implements IteratorAggregate
{
    /** @param list<Problem> $problems */
    private function __construct(private Json $with, private Node $read, private array $problems)
    {
    }

    public static function none(): self
    {
        return self::of(Json::object());
    }

    /** These options, as a JSON object; anything else is a problem of theirs. */
    public static function of(Json $with): self
    {
        return self::read($with, Node::config($with->line()));
    }

    public function text(Key $key): string|NotGiven|Problem
    {
        $at = $this->read->field($key->value());

        return match ($at->kind()) {
            Kind::Nothing => NotGiven::value(),
            Kind::Text => $at->text(),
            Kind::Map, Kind::List, Kind::Empty, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null => $at->mismatch(
                'text',
            ),
        };
    }

    public function flag(Key $key): bool|NotGiven|Problem
    {
        $at = $this->read->field($key->value());

        return match ($at->kind()) {
            Kind::Nothing => NotGiven::value(),
            Kind::Boolean => $at->boolean(),
            Kind::Map, Kind::List, Kind::Empty, Kind::Text, Kind::Integer, Kind::Number, Kind::Null => $at->mismatch(
                'true or false',
            ),
        };
    }

    public function integer(Key $key): int|NotGiven|Problem
    {
        $at = $this->read->field($key->value());

        return match ($at->kind()) {
            Kind::Nothing => NotGiven::value(),
            Kind::Integer => $at->integer(),
            Kind::Map, Kind::List, Kind::Empty, Kind::Text, Kind::Number, Kind::Boolean, Kind::Null => $at->mismatch(
                'a whole number',
            ),
        };
    }

    public function number(Key $key): float|NotGiven|Problem
    {
        $at = $this->read->field($key->value());

        return match ($at->kind()) {
            Kind::Nothing => NotGiven::value(),
            Kind::Integer, Kind::Number => $at->number(),
            Kind::Map, Kind::List, Kind::Empty, Kind::Text, Kind::Boolean, Kind::Null => $at->mismatch('a number'),
        };
    }

    /** A list of paths, each as text. */
    public function paths(Key $key): Paths|NotGiven|Problem
    {
        $at = $this->read->field($key->value());

        return match ($at->kind()) {
            Kind::Nothing => NotGiven::value(),
            Kind::Empty => Paths::none(),
            Kind::List => $this->pathsIn($at),
            Kind::Map, Kind::Text, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null => $at->mismatch(
                'a list of paths',
            ),
        };
    }

    /** @return Listed<string>|NotGiven|Problem a list of text */
    public function texts(Key $key): Listed|NotGiven|Problem
    {
        $at = $this->read->field($key->value());

        return match ($at->kind()) {
            Kind::Nothing => NotGiven::value(),
            Kind::Empty => Listed::of(),
            Kind::List => $this->textsIn($at),
            Kind::Map, Kind::Text, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null => $at->mismatch(
                'a list of text',
            ),
        };
    }

    /**
     * The options under a key that holds an object. Where it holds something else, the options under it are none,
     * with that as their problem.
     */
    public function object(Key $key): self|NotGiven
    {
        $at = $this->read->field($key->value());
        $under = Json::object();

        foreach ($this->with as $written => $value) {
            $under = $written === $key->value() ? $value : $under;
        }

        return $at->isPresent() ? self::read($under, $at) : NotGiven::value();
    }

    /** @return Listed<Problem> what is wrong with these options as a whole: that they are not an object */
    public function problems(): Listed
    {
        return Listed::of(...$this->problems);
    }

    /** The options as they are written, as a JSON object. */
    public function written(): Json
    {
        return $this->with;
    }

    /** @return Iterator<int, Key> the key of each option, in the order they are written */
    public function getIterator(): Iterator
    {
        foreach ($this->with as $key => $value) {
            yield Key::of(sprintf('%s', $key));
        }
    }

    private static function read(Json $with, Node $at): self
    {
        return match ($at->kind()) {
            Kind::Map, Kind::Empty, Kind::Nothing => new self($with, $at, []),
            Kind::List, Kind::Text, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null
                => new self(Json::object(), $at, [$at->mismatch('an object')]),
        };
    }

    private function pathsIn(Node $at): Paths|Problem
    {
        $texts = $this->textsIn($at);

        if ($texts instanceof Problem) {
            return $texts;
        }

        $paths = Paths::none();

        foreach ($texts as $text) {
            $paths = $paths->with(Path::of($text));
        }

        return $paths;
    }

    /** @return Listed<string>|Problem */
    private function textsIn(Node $at): Listed|Problem
    {
        $texts = [];

        foreach ($at->items() as $item) {
            if ($item->kind() !== Kind::Text) {
                return $item->mismatch('text');
            }

            $texts[] = $item->text();
        }

        return Listed::of(...$texts);
    }
}
