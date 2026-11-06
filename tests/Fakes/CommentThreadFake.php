<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use NightWorksIO\MutationGate\Core\Delivery\CommentThread;
use NightWorksIO\MutationGate\Core\Delivery\CommentWrite;
use NightWorksIO\MutationGate\Core\Written;
use RuntimeException;

/** A thread that answers each comment with where it was written, or fails a test that writes none it should not. */
final class CommentThreadFake implements CommentThread
{
    public private(set) string $markdown = '';

    public private(set) ?CommentWrite $write = null;

    public function written(string $markdown, CommentWrite $write): Written
    {
        if ($this->write instanceof CommentWrite) {
            throw new RuntimeException('The thread was written twice.');
        }

        $this->markdown = $markdown;
        $this->write = $write;

        return Written::to('the thread');
    }
}
