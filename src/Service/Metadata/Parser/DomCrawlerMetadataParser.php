<?php

use Symfony\Component\DomCrawler\Crawler;

class DomCrawlerMetadataParser implements MetadataParserInterface
{
    public function getMetadata(string $url, string $content): array
    {
        $crawler = new Crawler(null, $url, useHtml5Parser: true);
        $title = $crawler->filterXPath("//title")->text();
        $description = $crawler->filterXPath("//meta[@name='description']")->attr("content");
        $image = $crawler->filterXPath("//meta[@property='og:image']")->attr("content");
        $language = $crawler->filterXPath("//html")->attr("lang");

        return [
            "title" => $title,
            "description" => $description,
            "image" => $image,
            "language" => $language
        ];
    }
}