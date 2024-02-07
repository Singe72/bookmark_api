<?php

namespace App\Message;

class CacheMetadataMessage
{
    public function __construct(
        private int $bookmarkId
    ) {
    }

    public function getBookmarkId(): int
    {
        return $this->bookmarkId;
    }
}