<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * The pull request comment as a config shapes it (ADR-0102): `comment`, which the `Reports` part holds and each
 * comment reporter's `with.comment` lays over: what it shows, and when it is written.
 */
final readonly class CommentSettings
{
    private function __construct(private CommentShows $shows, private CommentWrites $writes)
    {
    }

    public static function of(CommentShows $shows, CommentWrites $writes): self
    {
        return new self($shows, $writes);
    }

    /** No key set: every default. */
    public static function standard(): self
    {
        return new self(CommentShows::of(), CommentWrites::of());
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self($this->shows->over($later->shows), $this->writes->over($later->writes));
    }

    public function shows(): CommentShows
    {
        return $this->shows;
    }

    public function writes(): CommentWrites
    {
        return $this->writes;
    }

    /** The `comment` member a config writes; nothing where no key is set. */
    public function written(): Member
    {
        return Member::unlessEmpty('comment', $this->json());
    }

    /** The keys set, as the object a config writes at `comment` or a comment reporter's `with.comment`. */
    public function json(): Json
    {
        return Json::object(...$this->shows->members(), ...$this->writes->members());
    }

    /** The PHP builder's calls for the keys set. */
    public function php(): PhpCalls
    {
        return PhpCalls::inWith(...$this->shows->calls(), ...$this->writes->calls());
    }
}
