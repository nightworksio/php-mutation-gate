<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;
use function implode;
use function is_bool;

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


    /** @param Listed<Section|State|Occasion>|Absent $cases */
    public static function listed(Listed|Absent $cases): Json|Absent
    {
        return $cases instanceof Listed
            ? Json::items(...array_map(static fn(Section|State|Occasion $case): string => $case->value, [...$cases]))
            : $cases;
    }

    /**
     * @param  list<Toggle> $among the toggles a part says of
     * @return list<Member>
     */
    public static function toggles(CommentToggles $set, array $among): array
    {
        return array_map(static fn(Toggle $toggle): Member => Member::of($toggle->value, $set->set($toggle)), $among);
    }

    /**
     * @param  Listed<Section|State|Occasion>|Absent $cases
     * @return list<string>
     */
    public static function casesCall(Key $key, Listed|Absent $cases): array
    {
        $written = array_map(
            static fn(Section|State|Occasion $case): string => sprintf(Declarations::METHOD, $key->enum(), $case->name),
            $cases instanceof Listed ? [...$cases] : [],
        );

        return $cases instanceof Listed ? [sprintf(self::CALL, $key->value, implode(', ', $written))] : [];
    }

    /** @return list<string> */
    public static function caseCall(Key $key, Lines|Detail|Mode|Passing|Absent $case): array
    {
        return $case instanceof Absent
            ? []
            : [sprintf(self::CALL, $key->value, sprintf(Declarations::METHOD, $key->enum(), $case->name))];
    }

    /**
     * @param  list<Toggle> $among the toggles a part says of
     * @return list<string>
     */
    public static function togglesCall(CommentToggles $set, array $among): array
    {
        $calls = [];

        foreach ($among as $toggle) {
            $on = $set->set($toggle);
            $named = sprintf(Declarations::METHOD, 'Toggle', $toggle->name);
            $calls = is_bool($on) ? [...$calls, sprintf(self::CALL, $on ? 'with' : 'without', $named)] : $calls;
        }

        return $calls;
    }
}
