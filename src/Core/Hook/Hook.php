<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hook;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;
use function str_contains;

/**
 * A git hook the gate installs (ADR-0010, decision 3). `pre-push` judges the
 * commits being pushed and blocks a push that fails; `pre-commit` shows the
 * score change, runs nothing and never blocks (ADR-0015, decision 10).
 */
enum Hook: string
{
    case PrePush = 'pre-push';
    case PreCommit = 'pre-commit';

    /** The line every hook the gate writes carries, by which it knows its own. */
    private const string MARK = <<<'MARK'
        # Written by `mutation-gate hook install`, and removed by `mutation-gate hook uninstall`.
        MARK;

    /** A hook the gate writes: a shell script that hands git's arguments and input to the gate. */
    private const string SCRIPT = "#!/bin/sh\n%s\n%s";

    /** The hook's file in the directory git runs hooks from. */
    public function file(): Path
    {
        return Path::of($this->value);
    }

    /** The whole hook the gate writes, calling the gate as the call says. */
    public function script(HookCall $call): Contents
    {
        return Contents::of(sprintf(self::SCRIPT, self::MARK, $call->script($this)));
    }

    /** Who wrote what is at the hook's file. */
    public function occupant(Contents|Missing $found): Occupant
    {
        return match (true) {
            $found instanceof Missing => Occupant::Nobody,
            str_contains($found->text(), self::MARK) => Occupant::TheGate,
            default => Occupant::Someone,
        };
    }
}
