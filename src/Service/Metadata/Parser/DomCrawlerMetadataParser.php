<?php

namespace App\Service\Metadata\Parser;

use Symfony\Component\DomCrawler\Crawler;

class DomCrawlerMetadataParser implements MetadataParserInterface
{
    public function getMetadata(string $url, string $content): array
    {
        $crawler = new Crawler($content, $url, useHtml5Parser: true);
        $title = $crawler->filterXPath("//title")->text("No title found");
        $description = $crawler->filterXPath("//meta[@name='description']")->attr("content", "No description found");
        $image = $crawler->filterXPath("//meta[@property='og:image']")->attr("content", "No image found");
        $language = $crawler->filterXPath("//html")->attr("lang", "No language found");

        return [
            "title" => $title,
            "description" => $description,
            "image" => $image,
            "language" => $language
        ];
    }
}