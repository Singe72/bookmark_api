<?php

namespace App\Service\Metadata\Crawler;

class CurlMetadataCrawler implements MetadataCrawlerInterface
{
    public function getContent(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);

        if ($response === false) {
            throw new \RuntimeException(curl_error($ch));
        }

        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        curl_close($ch);

        return [
            "statusCode" => $statusCode,
            "contentType" => $contentType,
            "content" => $response,
        ];
    }
}