<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use function array_map;

use NightWorksIO\MutationGate\Core\Comment\Cap;
use NightWorksIO\MutationGate\Core\Comment\Detail;
use NightWorksIO\MutationGate\Core\Comment\Key;
use NightWorksIO\MutationGate\Core\Comment\Lines;
use NightWorksIO\MutationGate\Core\Comment\Mode;
use NightWorksIO\MutationGate\Core\Comment\Occasion;
use NightWorksIO\MutationGate\Core\Comment\Passing;
use NightWorksIO\MutationGate\Core\Comment\Section;
use NightWorksIO\MutationGate\Core\Comment\State;
use NightWorksIO\MutationGate\Core\Comment\Toggle;
use NightWorksIO\MutationGate\Core\Config\Definition\CommentKeys;
use NightWorksIO\MutationGate\Core\Format\Json;

use function sprintf;

/** What the pull request comment shows and when it is written (ADR-0102): `comment`. */
final readonly class Comment implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `comment.sections`: the sections the comment shows, in this order, the verdict first. */
    public static function sections(Section ...$sections): self
    {
        return self::listing(Key::Sections, ...$sections);
    }

    /** `comment.open`: the sections shown open, the rest folded. */
    public static function open(Section ...$sections): self
    {
        return self::listing(Key::Open, ...$sections);
    }

    /** `comment.states`: the states of a run the comment is written in. */
    public static function states(State ...$states): self
    {
        return self::listing(Key::States, ...$states);
    }

    /** `comment.on`: where the comment is written. */
    public static function on(Occasion ...$occasions): self
    {
        return self::listing(Key::On, ...$occasions);
    }

    /** `comment.mode`: whether one comment is kept up to date, or each run writes a new one. */
    public static function mode(Mode $mode): self
    {
        return self::at(Key::Mode->value, $mode->value);
    }

    /** `comment.passing`: when a passing run writes the comment. */
    public static function passing(Passing $passing): self
    {
        return self::at(Key::Passing->value, $passing->value);
    }

    /** `comment.survivors.lines`: the survivors on changed lines, or on every line. */
    public static function lines(Lines $lines): self
    {
        return self::at(sprintf('%s.%s', Cap::Survivors->value, Key::Lines->value), $lines->value);
    }

    /** `comment.survivors.detail`: each survivor in full, or as a row. */
    public static function detail(Detail $detail): self
    {
        return self::at(sprintf('%s.%s', Cap::Survivors->value, Key::Detail->value), $detail->value);
    }

    /** `comment.<cap>.most`: the most entries of this list the comment shows, or characters of its text. */
    public static function most(Cap $cap, int $most): self
    {
        return self::at(sprintf('%s.most', $cap->value), $most);
    }

    /** Says what this toggle names: the comment shows it, or is written then. */
    public static function with(Toggle $toggle): self
    {
        return self::at($toggle->value, value: true);
    }

    /** Leaves out what this toggle names. */
    public static function without(Toggle $toggle): self
    {
        return self::at($toggle->value, value: false);
    }

    public function written(): Json
    {
        return $this->json;
    }

    private static function listing(Key $key, Section|State|Occasion ...$cases): self
    {
        return self::at(
            $key->value,
            Json::items(...array_map(static fn(Section|State|Occasion $case): string => $case->value, $cases)),
        );
    }

    private static function at(string $path, Json|string|int|bool $value): self
    {
        return new self(Json::at(sprintf('%s.%s', CommentKeys::KEY, $path), $value));
    }
}
