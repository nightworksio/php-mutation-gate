<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprint;

use function str_ends_with;

/** A file under the test directories, what it is to a content key, and what it declares and names. */
final readonly class TestFile
{
    private function __construct(private Fingerprint $fingerprint, private Role $role, private PhpFile $php)
    {
    }

    /** A file of test cases, as the runner finds them. */
    public static function testCase(Fingerprint $fingerprint, Contents $contents): self
    {
        return new self($fingerprint, Role::TestCase, PhpFile::read($contents));
    }

    /** Any other file: support where it is PHP that only declares, and loaded otherwise. */
    public static function other(Fingerprint $fingerprint, Contents $contents): self
    {
        $php = PhpFile::read($contents);
        $support = str_ends_with($fingerprint->path()->value(), '.php') && $php->onlyDeclares();

        return new self($fingerprint, $support ? Role::Support : Role::Loaded, $php);
    }

    public function fingerprint(): Fingerprint
    {
        return $this->fingerprint;
    }

    public function role(): Role
    {
        return $this->role;
    }

    public function php(): PhpFile
    {
        return $this->php;
    }
}
