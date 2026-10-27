<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

use Phar;

use function sprintf;

/**
 * The PHAR the gate runs from, where it runs from one (ADR-0022). It holds
 * the gate, its own manifest and its dependencies, scoped; the project's
 * packages stay in the project's vendor directory. A script of the gate's
 * own is started as the archive with the script's name first, since PHP
 * starts no script inside an archive by its path.
 */
final readonly class OwnArchive
{
    /** How PHP opens the archive's root. */
    private const string ROOT = 'phar://%s';

    /** How PHP opens a file in the archive. */
    private const string INSIDE = 'phar://%s/%s';

    /** The archive's autoloader, which loads the gate and its scoped dependencies. */
    private const string AUTOLOADER = 'vendor/autoload.php';

    /** A script of the gate's own, in the archive. */
    private const string SCRIPT = 'bin/%s';

    private function __construct(private string $file)
    {
    }

    /** The archive this process runs from; none where it runs from Composer's installation. */
    public static function running(): self|NotGiven
    {
        $file = Phar::running(returnPhar: false);

        return $file === '' ? NotGiven::value() : new self($file);
    }

    /** The archive at this file, as a test names one. */
    public static function at(string $file): self
    {
        return new self($file);
    }

    /** The archive's own file, on disk. */
    public function file(): string
    {
        return $this->file;
    }

    /** The archive's root, as PHP opens a directory in it. */
    public function root(): string
    {
        return sprintf(self::ROOT, $this->file);
    }

    /** A file in the archive, by its path from the archive's root, as PHP opens it. */
    public function inside(string $path): string
    {
        return sprintf(self::INSIDE, $this->file, $path);
    }

    /** The archive's autoloader, as PHP opens it. */
    public function autoloader(): string
    {
        return $this->inside(self::AUTOLOADER);
    }

    /** One of the gate's own scripts, in the archive, as PHP opens it. */
    public function script(OwnScript $script): string
    {
        return $this->inside(sprintf(self::SCRIPT, $script->value));
    }

    /** The script of the gate's own a command line starts the archive as: its first argument, where it names one. */
    public function scriptAskedBy(string ...$arguments): OwnScript|NotGiven
    {
        [, $asked] = [...$arguments, '', ''];

        return OwnScript::tryFrom($asked) ?? NotGiven::value();
    }
}
