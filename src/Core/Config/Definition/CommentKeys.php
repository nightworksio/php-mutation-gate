<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_map;

use BackedEnum;
use Closure;

use function is_int;

use NightWorksIO\MutationGate\Core\Comment\Cap;
use NightWorksIO\MutationGate\Core\Comment\Detail;
use NightWorksIO\MutationGate\Core\Comment\Key as CommentKey;
use NightWorksIO\MutationGate\Core\Comment\Lines;
use NightWorksIO\MutationGate\Core\Comment\Mode;
use NightWorksIO\MutationGate\Core\Comment\Occasion;
use NightWorksIO\MutationGate\Core\Comment\Passing;
use NightWorksIO\MutationGate\Core\Comment\Section as CommentSection;
use NightWorksIO\MutationGate\Core\Comment\State;
use NightWorksIO\MutationGate\Core\Comment\Toggle;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\BoundsOptions;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\CommentCaps;
use NightWorksIO\MutationGate\Core\Config\CommentSettings;
use NightWorksIO\MutationGate\Core\Config\CommentShows;
use NightWorksIO\MutationGate\Core\Config\CommentToggles;
use NightWorksIO\MutationGate\Core\Config\CommentWrites;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Reports;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * How the `comment` keys a config writes are read into its `Reports` part (ADR-0002, ADR-0102), and the same keys
 * under a comment reporter's `with.comment`: what the comment shows and when it is written, which reports and never
 * judges.
 *
 * @internal
 */
final readonly class CommentKeys
{
    public const string KEY = 'comment';

    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        return [Field::section(
            self::KEY,
            self::section(static fn(CommentSettings $comment): Layer => Layer::of(Reports::commenting($comment))),
        )];
    }

    /**
     * The `comment` object, read into what a build makes of the settings it holds.
     *
     * @template T of object
     *
     * @param  Closure(CommentSettings): T $build
     * @return Section<T>
     */
    public static function section(Closure $build): Section
    {
        $reports = Effect::JudgesOrReportsOnly;
        $fields = [
            Field::optional(CommentKey::Sections->value, VerdictFirst::sections(), $reports),
            Field::optional(CommentKey::Open->value, self::listOf(CommentSection::cases(), least: 0), $reports),
            Field::optional(CommentKey::States->value, self::listOf(State::cases(), least: 1), $reports),
            Field::optional(CommentKey::On->value, self::listOf(Occasion::cases(), least: 1), $reports),
            Field::optional(CommentKey::Mode->value, Enumerated::of(Mode::cases()), $reports),
            Field::optional(CommentKey::Passing->value, Enumerated::of(Passing::cases()), $reports),
            ...array_map(
                static fn(Toggle $toggle): Field => Field::optional($toggle->value, Flag::boolean(), $reports),
                Toggle::cases(),
            ),
            ...array_map(self::cap(...), Cap::cases()),
        ];

        return Section::of(
            static function (Node $comment) use ($fields, $build): object {
                $read = [];

                foreach ($fields as $field) {
                    $read[$field->key()] = $field->read($comment);
                }

                return Reading::built(static fn(): object => $build(self::settingsOf($read)), ...$read);
            },
            ...$fields,
        );
    }

    /** A comment reporter's `with.comment`, which the definition checked, read into its settings. */
    public static function settingsIn(Options $options): CommentSettings
    {
        $comment = $options->object(Key::of(self::KEY));
        $read = $comment instanceof Options
            ? self::section(static fn(CommentSettings $settings): CommentSettings => $settings)
                ->read(Node::config($comment->written()->line()))
                ->value()
            : CommentSettings::standard();

        return $read instanceof CommentSettings ? $read : CommentSettings::standard();
    }

    /**
     * A reporter's options; a comment reporter's with the run's `comment` laid under its own `with.comment`, key by
     * key, so each key it sets wins.
     */
    public static function forReporter(string $use, Options $options, CommentSettings $comment): Options
    {
        $reporter = BuiltinReporter::tryFrom($use);
        $laid = static fn(): Json => $comment->over(self::settingsIn($options))->json();

        return $reporter instanceof BuiltinReporter && $reporter->comments()
            ? $options->over(Json::object(Member::of(self::KEY, $laid())))
            : $options;
    }

    /** @param array<string, Reading<object|scalar>> $read by the key */
    private static function settingsOf(array $read): CommentSettings
    {
        $survivors = $read[Cap::Survivors->value]->value();

        return CommentSettings::of(
            CommentShows::of(
                sections: self::casesOf($read[CommentKey::Sections->value]->value(), CommentSection::class),
                open: self::casesOf($read[CommentKey::Open->value]->value(), CommentSection::class),
                caps: self::caps($read),
                toggles: self::toggles($read),
                lines: $survivors instanceof CapRead ? $survivors->lines : Absent::setting(),
                detail: $survivors instanceof CapRead ? $survivors->detail : Absent::setting(),
            ),
            CommentWrites::of(
                mode: self::caseOf($read[CommentKey::Mode->value]->value(), Mode::class),
                states: self::casesOf($read[CommentKey::States->value]->value(), State::class),
                passing: self::caseOf($read[CommentKey::Passing->value]->value(), Passing::class),
                on: self::casesOf($read[CommentKey::On->value]->value(), Occasion::class),
                toggles: self::toggles($read),
            ),
        );
    }

    /** @param array<string, Reading<object|scalar>> $read by the key */
    private static function caps(array $read): CommentCaps
    {
        $caps = CommentCaps::none();

        foreach (Cap::cases() as $cap) {
            $value = $read[$cap->value]->value();
            $caps = $value instanceof CapRead && is_int($value->most)
                ? $caps->over(CommentCaps::capping($cap, $value->most))
                : $caps;
        }

        return $caps;
    }

    /** @param array<string, Reading<object|scalar>> $read by the key */
    private static function toggles(array $read): CommentToggles
    {
        $toggles = CommentToggles::none();

        foreach (Toggle::cases() as $toggle) {
            $value = $read[$toggle->value]->value();
            $toggles = match ($value) {
                true => $toggles->over(CommentToggles::on($toggle)),
                false => $toggles->over(CommentToggles::off($toggle)),
                default => $toggles,
            };
        }

        return $toggles;
    }

    /**
     * @template E of BackedEnum
     *
     * @param  list<E>     $cases
     * @param  int<0, max> $least
     * @return Items<E>
     */
    private static function listOf(array $cases, int $least): Items
    {
        $identity = static fn(BackedEnum $case): string => (string) $case->value;

        return $least === 0
            ? Items::distinct(Enumerated::of($cases), $identity)
            : Items::distinctAtLeastOne(Enumerated::of($cases), $identity);
    }

    /**
     * `comment.<cap>`: its `most`, and for the survivors their `lines` and `detail`.
     *
     * @return Field<CapRead>
     */
    private static function cap(Cap $cap): Field
    {
        $reports = Effect::JudgesOrReportsOnly;
        $most = Field::optional(BoundsOptions::MOST, Integer::atLeast($cap === Cap::Text ? 1 : 0), $reports);
        $lines = Field::optional(CommentKey::Lines->value, Enumerated::of(Lines::cases()), $reports);
        $detail = Field::optional(CommentKey::Detail->value, Enumerated::of(Detail::cases()), $reports);
        $survivors = $cap === Cap::Survivors;

        $section = Section::of(
            static function (Node $at) use ($most, $lines, $detail, $survivors): CapRead|Invalid {
                $count = $most->read($at);
                $which = $survivors ? $lines->read($at) : Reading::nothing();
                $shown = $survivors ? $detail->read($at) : Reading::nothing();

                return Reading::built(
                    static fn(): CapRead => CapRead::of($count->value(), $which->value(), $shown->value()),
                    $count,
                    $which,
                    $shown,
                );
            },
            ...$survivors ? [$most, $lines, $detail] : [$most],
        );

        return Field::section($cap->value, $section);
    }

    /**
     * @template E of BackedEnum
     *
     * @param  class-string<E>  $enum
     * @return Listed<E>|Absent
     */
    private static function casesOf(object|string|int|float|bool $read, string $enum): Listed|Absent
    {
        if (! $read instanceof Listed) {
            return Absent::setting();
        }

        $cases = [];

        foreach ($read as $item) {
            $cases = $item instanceof $enum ? [...$cases, $item] : $cases;
        }

        return Listed::of(...$cases);
    }

    /**
     * @template E of BackedEnum
     *
     * @param  class-string<E> $enum
     * @return E|Absent
     */
    private static function caseOf(object|string|int|float|bool $read, string $enum): BackedEnum|Absent
    {
        return $read instanceof $enum ? $read : Absent::setting();
    }
}
