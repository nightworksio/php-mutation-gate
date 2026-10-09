<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hook;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Fit;

use function preg_match;
use function sprintf;
use function str_replace;

/**
 * How a hook calls the gate. Git runs a hook at the top of the working tree,
 * and the gate reads the project it is started in, so a project below the top
 * is entered first. The gate is called by the binary Composer installed.
 */
final readonly class HookCall
{
    /** A path the shell reads as it is written, with nothing to quote. */
    private const string PLAIN = '#^[A-Za-z0-9_./-]+$#';

    /** The gate's command for a hook, with git's arguments passed on. */
    private const string COMMAND = '%s "$@"';

    /** A hook the gate writes enters the project, or stops. */
    private const string ENTER = "cd %s || exit 1\nexec %s\n";

    /** A hook the gate writes where the project is the top of the working tree. */
    private const string AT_THE_TOP = "exec %s\n";

    /** The line added to someone else's hook, which leaves its directory as it was. */
    private const string SUBSHELL = '(cd %s && %s) || exit 1';

    /** The line added to someone else's hook where the project is the top of the working tree. */
    private const string LINE = '%s || exit 1';

    /** @param Path $project the project as the top of the working tree spells it */
    private function __construct(private Path $project, private Path $binary)
    {
    }

    /**
     * The gate in this project, as the top of the working tree spells it, by
     * this binary, as the project spells it.
     */
    public static function of(Path $project, Path $binary): self
    {
        return new self($project, $binary);
    }

    /** The lines of a hook the gate writes that call the gate. */
    public function script(Hook $hook): string
    {
        return $this->atTheTop()
            ? sprintf(self::AT_THE_TOP, $this->command($hook))
            : sprintf(self::ENTER, $this->quoted($this->project), $this->command($hook));
    }

    /** The one line to add to a hook the gate did not write, to call the gate from it. */
    public function line(Hook $hook): string
    {
        return $this->atTheTop()
            ? sprintf(self::LINE, $this->command($hook))
            : sprintf(self::SUBSHELL, $this->quoted($this->project), $this->command($hook));
    }

    /** The gate's command for a hook, as the shell reads it, with no arguments: what a hook manager runs. */
    public function called(Hook $hook): string
    {
        return sprintf(Fit::JOINED, $this->quoted($this->binary), $hook->value);
    }

    private function atTheTop(): bool
    {
        return $this->project->equals(Path::root());
    }

    private function command(Hook $hook): string
    {
        return sprintf(self::COMMAND, $this->called($hook));
    }

    /** A path as the shell reads it: as it is where that is safe, and otherwise in single quotes. */
    private function quoted(Path $path): string
    {
        return preg_match(self::PLAIN, $path->value()) === 1
            ? $path->value()
            : sprintf("'%s'", str_replace("'", "'\\''", $path->value()));
    }
}
