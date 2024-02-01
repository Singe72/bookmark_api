<?php

namespace App\Service\Metadata\Crawler;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: "app.httpcrawler", public: true)]
class HttpClientMetadataCrawler implements MetadataCrawlerInterface
{
    public function __construct(
        private HttpClientInterface $client,
    ) {
    }

    public function getContent(string $url): array
    {
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