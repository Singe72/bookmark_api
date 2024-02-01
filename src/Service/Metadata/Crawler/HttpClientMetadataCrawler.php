<?php

namespace App\Service\Metadata\Crawler;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class HttpClientMetadataCrawler implements MetadataCrawlerInterface
{
    public function __construct(
        private HttpClientInterface $client,
    ) {
    }

    public function getContent(string $url): array
    {
        $this->client = $this->client->withOptions([
           "proxy" => "http://proxy.univ-lemans.fr:3128"
        ]);
        $response = $this->client->request("GET", $url);

        $statusCode = $response->getStatusCode();
        $contentType = $response->getHeaders()["content-type"][0];
        $content = $response->getContent();

        return [
            "statusCode" => $statusCode,
            "contentType" => $contentType,
            "content" => $content,
        ];
    }
}