<?php

namespace App\MessageHandler;

use App\Message\CacheMetadataMessage;
use App\Service\Metadata\Crawler\MetadataCrawlerInterface;
use App\Service\Metadata\Parser\DomCrawlerMetadataParser;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class CacheMetadataMessageHandler
{
    private $cache;
    private $crawler;
    private $parser;
    private $logger;

    public function __construct(CacheInterface $cache, MetadataCrawlerInterface $crawler, DomCrawlerMetadataParser $parser, LoggerInterface $logger)
    {
        $this->cache = $cache;
        $this->crawler = $crawler;
        $this->parser = $parser;
        $this->logger = $logger;
    }

    public function __invoke(CacheMetadataMessage $message): void
    {
        $bookmarkId = $message->getBookmarkId();

        $this->cache->get("bookmark_metadata_" . $bookmarkId, function (ItemInterface $item) use ($bookmarkId) {
            $this->logger->debug("Caching metadata for Bookmark ID: $bookmarkId asynchronously");
            $item->expiresAfter(\DateInterval::createFromDateString("5 minutes"));
            $content = $this->crawler->getContent($bookmarkId);
            return $this->parser->getMetadata($bookmarkId, $content["content"]);
        });
    }
}
