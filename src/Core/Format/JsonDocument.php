<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function array_search;
use function array_slice;
use function count;
use function is_int;
use function json_encode;
use function mb_substr;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Migration\KeyPath;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;
use function str_contains;

/**
 * A JSON object as its file writes it, edited key by key (ADR-0026,
 * decision 3): each edit changes the bytes of the keys and values it
 * touches, and leaves every other byte, the file's whitespace and its key
 * order among them, as it was.
 */
final readonly class JsonDocument
{
    private const string NOT_AN_OBJECT = 'The file holds JSON, but not an object.';

    private const string NEWLINE = "\n";

    private const string EMPTIED = "{\n%s%s\n%s}";

    private const string AFTER_LINE = ",\n%s%s";

    private const string AFTER_INLINE = ', %s';

    private function __construct(private string $text, private JsonSpan $root)
    {
    }

    /** A JSON object's text, or why it is not one. */
    public static function parse(string $json): self|CannotJudge
    {
        $parsed = Json::parse($json);

        if ($parsed instanceof CannotJudge) {
            return $parsed;
        }

        $root = JsonScanner::scan($json);

        return $root->isObject ? new self($json, $root) : CannotJudge::because(self::NOT_AN_OBJECT);
    }

    public function text(): string
    {
        return $this->text;
    }

    public function has(KeyPath $path): bool
    {
        return $this->member($path) instanceof JsonMember;
    }

    /** The value under a key, as the file writes it; none where the file writes no such key. */
    public function fragment(KeyPath $path): JsonFragment|NotGiven
    {
        $member = $this->member($path);

        if (! $member instanceof JsonMember) {
            return NotGiven::value();
        }

        $indent = JsonIndent::at($this->text, $member->keyStart);

        return JsonFragment::of(JsonIndent::outdented($this->between($member->value), $indent));
    }

    /** The key at one path given the name another ends in, where it stands; as it was where there is no such key. */
    public function renamed(KeyPath $from, KeyPath $to): self
    {
        $member = $this->member($from);

        return $member instanceof JsonMember
            ? $this->spliced($member->keyStart, $member->keyEnd, json_encode($to->key(), JsonText::FLAGS))
            : $this;
    }

    /**
     * The document without the key at this path, and without each object
     * that held only it; as it was where there is none.
     */
    public function without(KeyPath $path): self
    {
        $parent = $path->parent();
        $object = $this->objectAt($parent);
        $member = $object instanceof JsonSpan ? $object->member($path->key()) : NotGiven::value();

        if (! $object instanceof JsonSpan || ! $member instanceof JsonMember) {
            return $this;
        }

        [$start, $end] = $this->removal($object, $member);
        $removed = $this->spliced($start, $end, '');
        $emptied = $parent instanceof KeyPath ? $removed->objectAt($parent) : NotGiven::value();

        return $emptied instanceof JsonSpan && $emptied->members === []
            ? $removed->without($parent)
            : $removed;
    }

    /**
     * The key at one path, and its value as written, moved to another, with
     * each object the new place needs; an object the old place leaves empty
     * goes too. As it was where nothing is written at the first path, or the
     * second cannot be written.
     */
    public function moved(KeyPath $from, KeyPath $to): self
    {
        $value = $this->fragment($from);

        if (! $value instanceof JsonFragment) {
            return $this;
        }

        $added = $this->with($to, $value);

        return $added->has($to) ? $added->without($from) : $this;
    }

    /**
     * The document with this value under the key at this path: in place of
     * the value written there, or added after the last member of the deepest
     * object that holds the path, with each object it needs. As it was where
     * a key on the way holds something other than an object.
     */
    public function with(KeyPath $path, JsonFragment $value): self
    {
        $member = $this->member($path);

        if ($member instanceof JsonMember) {
            $indent = JsonIndent::at($this->text, $member->keyStart);

            $written = JsonIndent::indented($value->text(), $indent);

            return $this->spliced($member->value->start, $member->value->end, $written);
        }

        $keys = [...$path];
        $object = $this->root;
        $depth = 0;

        $held = $object->member($keys[0]);

        // The path's last key is not written here, so the walk ends before it.
        while ($held instanceof JsonMember) {
            if (! $held->value->isObject) {
                return $this;
            }

            $object = $held->value;
            $depth++;
            $held = $object->member($keys[$depth]);
        }

        return $this->into($object, $keys[$depth], array_slice($keys, $depth + 1), $value);
    }

    /** @param list<string> $further */
    private function into(JsonSpan $object, string $key, array $further, JsonFragment $value): self
    {
        $layout = $this->root->members === []
            ? JsonIndent::standard()
            : JsonIndent::of($this->text, $this->root->members[0]->keyStart);
        $indent = JsonIndent::at($this->text, $object->start);

        if ($object->members === []) {
            $inner = $layout->deeper($indent);

            return $this->spliced(
                $object->start,
                $object->end,
                sprintf(self::EMPTIED, $inner, $layout->member($key, $further, $value, $inner), $indent),
            );
        }

        $first = $object->members[0];
        $last = $object->members[count($object->members) - 1];
        $memberIndent = JsonIndent::at($this->text, $last->keyStart);
        $written = $layout->member($key, $further, $value, $memberIndent);
        $opening = mb_substr($this->text, $object->start, $first->keyStart - $object->start);
        $onLines = str_contains($opening, self::NEWLINE);

        return $this->spliced(
            $last->value->end,
            $last->value->end,
            $onLines ? sprintf(self::AFTER_LINE, $memberIndent, $written) : sprintf(self::AFTER_INLINE, $written),
        );
    }

    /**
     * The bytes that go with a member: from the end of the one before it, from
     * its key to the next one's where it is the first, or everything between
     * the braces where it is the only one.
     *
     * @return array{int, int}
     */
    private function removal(JsonSpan $object, JsonMember $member): array
    {
        $members = $object->members;
        $at = array_search($member, $members, strict: true);

        return match (true) {
            count($members) === 1 => [$object->start + 1, $object->end - 1],
            is_int($at) && $at > 0 => [$members[$at - 1]->value->end, $member->value->end],
            default => [$member->keyStart, $members[1]->keyStart],
        };
    }

    private function member(KeyPath $path): JsonMember|NotGiven
    {
        $object = $this->objectAt($path->parent());

        return $object instanceof JsonSpan ? $object->member($path->key()) : NotGiven::value();
    }

    /** The object at this path, the top where none is given; none where nothing, or no object, is written there. */
    private function objectAt(KeyPath|NotGiven $path): JsonSpan|NotGiven
    {
        $object = $this->root;

        foreach ($path instanceof KeyPath ? $path : [] as $key) {
            $member = $object->member($key);

            if (! $member instanceof JsonMember || ! $member->value->isObject) {
                return NotGiven::value();
            }

            $object = $member->value;
        }

        return $object;
    }

    private function between(JsonSpan $span): string
    {
        return mb_substr($this->text, $span->start, $span->end - $span->start);
    }

    private function spliced(int $start, int $end, string $written): self
    {
        $text = sprintf('%s%s%s', mb_substr($this->text, 0, $start), $written, mb_substr($this->text, $end));

        return new self($text, JsonScanner::scan($text));
    }
}
