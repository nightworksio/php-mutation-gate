<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function is_int;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function sprintf;

/**
 * Whether a patched Pest run judges its mutants in split streams, and how
 * (ADR-0117): `splitStream`, which the `Pest` part holds, as only the Pest
 * runner splits its streams.
 */
final readonly class SplitStream
{
    /** The key, and the Pest runner's option, that says whether a run splits its streams. */
    public const string ENABLED = 'enabled';

    /** The key, and the Pest runner's option, that says how many children a stream holds at once. */
    public const string CHILDREN = 'children';

    /** The key, and the Pest runner's option, that says whether a stream forks only where a mutant changes a value. */
    public const string CHANGE_CHECK = 'changeCheck';

    /** The key, and the Pest runner's option, that says whether a stream forks first at each mutant's likely killer. */
    public const string KILLERS_FIRST = 'killersFirst';

    /** The key that says what a run that splits its streams cuts its shards by. */
    public const string SHARDING = 'sharding';

    /** The section the keys are in, and the Pest runner's option that holds them. */
    public const string SECTION = 'splitStream';

    private const int CHILDREN_AT_ONCE = 1;

    private function __construct(
        private bool|Absent $enabled,
        private int|Absent $children,
        private bool|Absent $changeCheck,
        private bool|Absent $killersFirst,
        private StreamSharding|Absent $sharding,
    ) {
    }

    public static function of(
        bool|Absent $enabled = new Absent(),
        int|Absent $children = new Absent(),
        bool|Absent $changeCheck = new Absent(),
        bool|Absent $killersFirst = new Absent(),
        StreamSharding|Absent $sharding = new Absent(),
    ): self {
        return new self($enabled, $children, $changeCheck, $killersFirst, $sharding);
    }

    /** Every key at the value it takes when every layer leaves it out. */
    public static function standard(): self
    {
        $none = self::of();

        return self::of(
            $none->enabled(),
            $none->children(),
            $none->changeCheck(),
            $none->killersFirst(),
            $none->sharding(),
        );
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->enabled, $later->enabled),
            Absent::laid($this->children, $later->children),
            Absent::laid($this->changeCheck, $later->changeCheck),
            Absent::laid($this->killersFirst, $later->killersFirst),
            Absent::laid($this->sharding, $later->sharding),
        );
    }

    /** `splitStream.enabled`: whether a patched run judges its mutants in split streams; false by default. */
    public function enabled(): bool
    {
        return $this->enabled === true;
    }

    /** `splitStream.children`: the most children a stream holds at once; 1 by default. */
    public function children(): int
    {
        return is_int($this->children) ? $this->children : self::CHILDREN_AT_ONCE;
    }

    /** `splitStream.changeCheck`: whether a stream forks a pure expression's mutant only where it changes its value; true by default. */
    public function changeCheck(): bool
    {
        return $this->changeCheck !== false;
    }

    /** `splitStream.killersFirst`: whether a stream forks each mutant first at the test the ledger last saw kill it; true by default. */
    public function killersFirst(): bool
    {
        return $this->killersFirst !== false;
    }

    /** `splitStream.sharding`: what a run that splits its streams cuts its shards by; test files by default. */
    public function sharding(): StreamSharding
    {
        return $this->sharding instanceof StreamSharding ? $this->sharding : StreamSharding::Tests;
    }

    /** The Pest runner's `splitStream` option: every key at the value it takes. */
    public function option(): Member
    {
        return Member::of(self::SECTION, Json::object(
            Member::of(self::ENABLED, $this->enabled()),
            Member::of(self::CHILDREN, $this->children()),
            Member::of(self::CHANGE_CHECK, $this->changeCheck()),
            Member::of(self::KILLERS_FIRST, $this->killersFirst()),
        ));
    }

    /** The `splitStream` member a config writes; nothing where no key is set. */
    public function written(): Member
    {
        return Member::unlessEmpty(self::SECTION, Json::object(
            Member::of(self::ENABLED, $this->enabled),
            Member::of(self::CHILDREN, $this->children),
            Member::of(self::CHANGE_CHECK, $this->changeCheck),
            Member::of(self::KILLERS_FIRST, $this->killersFirst),
            Member::of(self::SHARDING, $this->sharding instanceof StreamSharding ? $this->sharding->value : $this->sharding),
        ));
    }

    /** The PHP builder's calls for the keys set. */
    public function php(): PhpCalls
    {
        return PhpCalls::inWith(
            ...PhpCalls::switched($this->enabled, 'SplitStream::on()', 'SplitStream::off()'),
            ...is_int($this->children) ? [sprintf('SplitStream::children(%d)', $this->children)] : [],
            ...PhpCalls::switched($this->changeCheck, 'SplitStream::checkingChanges()', 'SplitStream::forkingUnchecked()'),
            ...PhpCalls::switched($this->killersFirst, 'SplitStream::killersFirst()', 'SplitStream::everyTestAlike()'),
            ...$this->sharding instanceof StreamSharding ? [$this->sharding->php()] : [],
        );
    }
}
