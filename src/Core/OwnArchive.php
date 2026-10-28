<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

use function count;
use function mb_strlen;
use function mb_substr;

use Phar;

use function sprintf;
use function str_starts_with;
use function var_export;

/**
 * The PHAR the gate runs from, where it runs from one (ADR-0022). It holds
 * the gate, its own manifest and its dependencies, scoped; the project's
 * packages stay in the project's vendor directory. A script of the gate's
 * own is started as the archive with the script's name first, since PHP
 * starts no script inside an archive by its path. Prepended to another
 * script with `auto_prepend_file`, the archive loads its own autoloader
 * alone; another script loads it by its file and the name the archive is
 * known by, since PHP opens a file inside an archive whose name does not end
 * in `.phar` only once something has loaded it.
 */
final readonly class OwnArchive
{
    /** The name the archive is known by in a PHP process that loaded it, as its stub names it. */
    public const string ALIAS = 'mutation-gate.phar';

    /** How PHP opens the archive's root. */
    private const string ROOT = 'phar://%s';

    /** How PHP names a file inside an archive, before the archive's own path. */
    private const string SCHEME = 'phar://';

    /** How PHP opens a file in the archive. */
    private const string INSIDE = 'phar://%s/%s';

    /** The archive's autoloader, which loads the gate and its scoped dependencies. */
    private const string AUTOLOADER = 'vendor/autoload.php';

    /** A script of the gate's own, in the archive. */
    private const string SCRIPT = 'bin/%s';

    /** The statement that loads the archive in another PHP process. */
    private const string LOADING = "\\Phar::loadPhar(%s, %s);\n";

    private function __construct(private string $file)
    {
    }

    /** The archive this process runs from; none where it runs from Composer's installation. */
    public static function running(): self|NotGiven
    {
        return self::rootedAt(Phar::running());
    }

    /** The archive whose root PHP opens at this URL; none where it is no archive's root, as outside one it is empty. */
    public static function rootedAt(string $root): self|NotGiven
    {
        return str_starts_with($root, self::SCHEME)
            ? new self(mb_substr($root, mb_strlen(self::SCHEME)))
            : NotGiven::value();
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

    /** The statement that loads the archive in another PHP process, by its file and its name. */
    public function loading(): string
    {
        return sprintf(self::LOADING, var_export($this->file, return: true), var_export(self::ALIAS, return: true));
    }

    /** The archive's autoloader, as a PHP process that loaded the archive opens it. */
    public function autoloader(): string
    {
        return sprintf(self::INSIDE, self::ALIAS, self::AUTOLOADER);
    }

    /** One of the gate's own scripts, in the archive, as PHP opens it. */
    public function script(OwnScript $script): string
    {
        return $this->inside(sprintf(self::SCRIPT, $script->value));
    }

    /** The script of the gate's own a command line starts the archive as: its first argument, where it names one. */
    public function scriptAskedBy(string ...$arguments): OwnScript|NotGiven
    {
        $asked = count($arguments) > 1 ? OwnScript::tryFrom($arguments[1]) : null;

        return $asked ?? NotGiven::value();
    }
}
