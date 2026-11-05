<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_key_exists;
use function array_map;
use function implode;
use function is_array;

use NightWorksIO\MutationGate\Core\Comment\Detail;
use NightWorksIO\MutationGate\Core\Comment\Key;
use NightWorksIO\MutationGate\Core\Comment\Lines;
use NightWorksIO\MutationGate\Core\Comment\Mode;
use NightWorksIO\MutationGate\Core\Comment\Occasion;
use NightWorksIO\MutationGate\Core\Comment\Passing;
use NightWorksIO\MutationGate\Core\Comment\Section;
use NightWorksIO\MutationGate\Core\Comment\State;
use NightWorksIO\MutationGate\Core\Comment\Toggle;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Php\Declarations;

use function sprintf;

/**
 * The `comment` keys as a config file and the PHP builder write them (ADR-0102): a list of words, a word, and
 * whether something is said, each only where a layer sets it.
 *
 * @internal
 */
final readonly class CommentWritten
{
    private const string CALL = 'Comment::%s(%s)';


    /** @param list<Section|State|Occasion>|Absent $cases */
    public static function listed(array|Absent $cases): Json|Absent
    {
        return is_array($cases)
            ? Json::items(...array_map(static fn(Section|State|Occasion $case): string => $case->value, $cases))
            : $cases;
    }

    /**
     * @param  array<string, bool> $set   by the toggle's name, those set
     * @param  list<Toggle>        $among the toggles a part says of
     * @return list<Member>
     */
    public static function toggles(array $set, array $among): array
    {
        return array_map(
            static fn(Toggle $toggle): Member => Member::of(
                $toggle->value,
                array_key_exists($toggle->value, $set) ? $set[$toggle->value] : new Absent(),
            ),
            $among,
        );
    }

    /**
     * @param  list<Section|State|Occasion>|Absent $cases
     * @return list<string>
     */
    public static function casesCall(Key $key, array|Absent $cases): array
    {
        $written = is_array($cases)
            ? array_map(
                static fn(Section|State|Occasion $case): string => sprintf(
                    Declarations::METHOD,
                    $key->enum(),
                    $case->name,
                ),
                $cases,
            )
            : [];

        return is_array($cases) ? [sprintf(self::CALL, $key->value, implode(', ', $written))] : [];
    }

    /** @return list<string> */
    public static function caseCall(Key $key, Lines|Detail|Mode|Passing|Absent $case): array
    {
        return $case instanceof Absent
            ? []
            : [sprintf(self::CALL, $key->value, sprintf(Declarations::METHOD, $key->enum(), $case->name))];
    }

    /**
     * @param  array<string, bool> $set by the toggle's name, those set
     * @return list<string>
     */
    public static function togglesCall(array $set): array
    {
        $calls = [];

        foreach ($set as $toggle => $on) {
            $named = sprintf(Declarations::METHOD, 'Toggle', Toggle::from($toggle)->name);
            $calls[] = sprintf(self::CALL, $on ? 'with' : 'without', $named);
        }

        return $calls;
    }
}
