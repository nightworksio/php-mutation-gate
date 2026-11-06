<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\Context as Contexts;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** A context block (ADR-0071, ADR-0104): `contexts.<context>`, what a run in that context lays over the rest. */
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

    /** `contexts.github`: a run in GitHub Actions, over `ci` and under `pr` and `main`. */
    public static function gitHub(Gate $block): self
    {
        return self::block(Contexts::GitHub, $block);
    }

    /** `contexts.gitlab`: a run in GitLab CI, over `ci` and under `pr` and `main`. */
    public static function gitLab(Gate $block): self
    {
        return self::block(Contexts::GitLab, $block);
    }

    /** `contexts.bitbucket`: a run in Bitbucket Pipelines, over `ci` and under `pr` and `main`. */
    public static function bitbucket(Gate $block): self
    {
        return self::block(Contexts::Bitbucket, $block);
    }

    /** `contexts.azure`: a run in Azure Pipelines, over `ci` and under `pr` and `main`. */
    public static function azure(Gate $block): self
    {
        return self::block(Contexts::Azure, $block);
    }

    /** `contexts.buildkite`: a run in Buildkite, over `ci` and under `pr` and `main`. */
    public static function buildkite(Gate $block): self
    {
        return self::block(Contexts::Buildkite, $block);
    }

    /** `contexts.jenkins`: a run in Jenkins, over `ci` and under `pr` and `main`. */
    public static function jenkins(Gate $block): self
    {
        return self::block(Contexts::Jenkins, $block);
    }

    /** `contexts.circleci`: a run in CircleCI, over `ci` and under `pr` and `main`. */
    public static function circleCi(Gate $block): self
    {
        return self::block(Contexts::CircleCi, $block);
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
