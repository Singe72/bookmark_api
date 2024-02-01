<?php

namespace App\Service\Metadata\Crawler;

interface MetadataCrawlerInterface
{
    /**
     * @return array{
     *  contentType: string,
     *  content: string,
     *  statusCode: int
     * }
     */
    public function getContent(string $url): array;
}
