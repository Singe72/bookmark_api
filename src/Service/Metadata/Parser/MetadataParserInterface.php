<?php

namespace App\Service\Metadata\Parser;

interface MetadataParserInterface
{
    /**
     * @return array{
     *  title: string,
     *  description: string,
     *  image: string,
     *  language: string
     * }
     */
    public function getMetadata(string $url, string $content): array;
}
