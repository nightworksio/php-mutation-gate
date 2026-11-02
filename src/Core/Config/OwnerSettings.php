<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Owner\OwnerMention;

use function sprintf;

/**
 * The `owners` keys of a layer of config (ADR-0022): `owners.floors`, the
 * floor of each owner CODEOWNERS names that has one, and `owners.mention`,
 * whether the pull request comment mentions owners.
 */
final readonly class OwnerSettings
{
    private function __construct(private Table|Absent $floors, private OwnerMention|Absent $mention)
    {
    }

    public static function of(Table|Absent $floors = new Absent(), OwnerMention|Absent $mention = new Absent()): self
    {
        return new self($floors, $mention);
    }

    /** These settings with a later layer's laid over them: what the later one sets wins. */
    public function over(self $later): self
    {
        return new self(Absent::laid($this->floors, $later->floors), Absent::laid($this->mention, $later->mention));
    }

    /** Every setting at the value it takes when every layer leaves it out. */
    public function standard(): self
    {
        return new self($this->floors(), $this->mention());
    }

    /** `owners.floors`: the floor of each owner that has one, by the owner as CODEOWNERS spells it; none by default */
    public function floors(): Table
    {
        return $this->floors instanceof Table ? $this->floors : Table::none();
    }

    /** `owners.mention`: whether the comment mentions an owner the first time it gives it a survivor, or never */
    public function mention(): OwnerMention
    {
        return $this->mention instanceof OwnerMention ? $this->mention : OwnerMention::First;
    }

    /** What these set, as the `owners` key of a config writes it; left out where they set nothing. */
    public function written(): Member
    {
        $owners = Json::object(
            Member::of('floors', $this->floors instanceof Table ? $this->floors->written() : $this->floors),
            Member::of('mention', $this->mention instanceof OwnerMention ? $this->mention->value : $this->mention),
        );

        return Member::unlessEmpty('owners', $owners);
    }

    /** What these set, as the `Owner` settings `with()` takes. */
    public function php(): PhpCalls
    {
        $calls = [];

        foreach ($this->floors instanceof Table ? $this->floors : [] as $owner => $floor) {
            $calls[] = sprintf('Owner::floor(%s, %s)', PhpCalls::literal($owner), PhpCalls::literal($floor));
        }

        return PhpCalls::inWith(...match ($this->mention) {
            OwnerMention::First => [...$calls, 'Owner::mentionedFirst()'],
            OwnerMention::Never => [...$calls, 'Owner::neverMentioned()'],
            default => $calls,
        });
    }
}
