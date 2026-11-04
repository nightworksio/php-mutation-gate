<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\Context as Contexts;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** A context block (ADR-0071): `contexts.<context>`, the settings a run in that context lays over the rest. */
final readonly class Context implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `contexts.local`: a run outside CI. */
    public static function local(Gate $block): self
    {
        return self::block(Contexts::Local, $block);
    }

    /** `contexts.ci`: every run in CI. */
    public static function ci(Gate $block): self
    {
        return self::block(Contexts::Ci, $block);
    }

    /** `contexts.pr`: a run in CI on a pull request or a merge group, over `ci`. */
    public static function pullRequest(Gate $block): self
    {
        return self::block(Contexts::PullRequest, $block);
    }

    /** `contexts.main`: a run in CI on the default branch, over `ci`. */
    public static function main(Gate $block): self
    {
        return self::block(Contexts::Main, $block);
    }

    public function written(): Json
    {
        return $this->json;
    }

    private static function block(Contexts $context, Gate $block): self
    {
        $written = Json::object(Member::of($context->value, $block->written()));

        return new self(Json::object(Member::of('contexts', $written)));
    }
}
