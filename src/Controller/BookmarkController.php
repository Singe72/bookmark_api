<?php

namespace App\Controller;

use App\Entity\Bookmark;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\Annotation\Route;

class BookmarkController extends AbstractController
{
    /**
     * @Route("/bookmark", name="app_bookmark")
     */
    public function index(): JsonResponse
    {
        return $this->json([
            "message" => "Welcome to your new controller!",
            "path" => "src/Controller/BookmarkController.php",
        ]);
    }

    /**
     * @Route("/api/bookmarks", name="read_collection", methods={"GET"}, priority=2)
     * @Route("/api/bookmarks/pages/{!page}", name="read_collection_page", methods={"GET"}, requirements={"page"="\d+"}, defaults={"page":1})
     * @Route("/api/bookmarks/pages/{page}/{step}", name="read_collection_page_step", methods={"GET"}, requirements={"page"="\d+", "step"="\d+"}, defaults={"page":1})
     * @Route("/api/bookmarks/last", name="read_collection_last_page", methods={"GET"})
     * @Route("/api/bookmarks/last/{step}", name="read_collection_last_page_step", methods={"GET"}, requirements={"step"="\d+"})
     */
    public function read_bookmark_collection($page = 1, $step = 10, ManagerRegistry $doctrine, UrlHelper $urlHelper, Request $request): JsonResponse
    {
        $nbtotal = $doctrine->getRepository(Bookmark::class)->countAll();
        $current = (in_array($request->attributes->get("_route"), ["read_collection_last_page", "read_collection_last_page_step"])) ? max(1, $nbtotal - $step) : ($page - 1) * $step + 1;

        $response = new JsonResponse();
        $response->headers->set("Server", "BookmarkAPI");

        $baseUrl = $urlHelper->getAbsoluteUrl($this->generateUrl("read_collection"));
        $response->headers->set("Link", "<$baseUrl/last/>; rel=\"last\"");
        $response->headers->set("Link", "<$baseUrl>; rel=\"first\"", false);
        $response->headers->set("X-Total-Count", $nbtotal);
        $response->headers->set("X-Current-Page", intdiv($current, $step) + 1);

        $bookmarks = $doctrine->getRepository(Bookmark::class)->findNextX($current - 1, $step);

        $json_data = [];

        foreach ($bookmarks as $bookmark) {
            $bookmarkId = $bookmark->getId();
            $json_data["Locations"][] = "$baseUrl/$bookmarkId";
        }
        $response->headers->set("X-Page-Size", count($bookmarks));

        $json_data["meta"] = ["total_count" => count($bookmarks)];

        if ($current > $nbtotal) {
            $response->setStatusCode(Response::HTTP_NO_CONTENT, "No Content");
            $response->setContent("Max page number reached");
            return $response;
        }

        if (!in_array($request->attributes->get("_route"), ["read_collection_last_page", "read_collection_last_page_step"])) {
            $nextpage = intdiv($current, $step) + 2;
            $response->headers->set("Link", "<$baseUrl/pages/$nextpage>; rel=\"next\"", false);
        }

        $nextXbookmarks = ($current + $step <= $nbtotal) ? $current + $step : $nbtotal;

        $response->headers->set("Content-range", "urls $current-$nextXbookmarks/$nbtotal");
        $response->setData($json_data);
        $response->setStatusCode(Response::HTTP_PARTIAL_CONTENT);

        $response->setCache([
            "etag" => sha1($response->getContent().$response->headers->get("X-Total-Count").$response->headers->get("X-Current-page").$response->headers->get("X-Page-Size")),
            "max_age" => 60,
            "public" => true
        ]);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * @Route("/api/bookmarks", name="create_bookmark", methods={"POST"})
     */
    public function createBookmark(ManagerRegistry $doctrine, Request $request, UrlHelper $urlHelper): Response
    {
        $response = new Response();
        $response->headers->set("Server", "BookmarkAPI");

        $name = $request->get("name");
        $description = $request->get("description");
        $url = $request->get("url");

        if (!isset($url) or !isset($name)) {
            $response->setStatusCode(Response::HTTP_BAD_REQUEST, "Bad Request");
            $response->setContent("Name and URL must not be empty");
            return $response;
        }
        if (strlen($name) > 255) {
            $response->setStatusCode(Response::HTTP_BAD_REQUEST, "Bad Request");
            $response->setContent("Name must be less than 255 characters");
            return $response;
        }

        $entityManager = $doctrine->getManager();

        $bookmark = new Bookmark();
        $bookmark->setName($name);
        $bookmark->setDescription($description);
        $bookmark->setUrl($url);
        $bookmark->setLastupdate(new \DateTime("now", new \DateTimeZone(date_default_timezone_get())));

        $entityManager->persist($bookmark);
        $entityManager->flush();

        $id = $bookmark->getId();

        $response->setStatusCode(Response::HTTP_CREATED, "Created");
        $response->setContent("Created");
        $response->headers->set(
            "Location",
            $urlHelper->getAbsoluteUrl("/api/bookmarks/$id")
        );

        return $response;
    }

    /**
     * @Route("/api/bookmarks/latest", name="read_last_bookmark", methods={"GET"})
     * @Route("/api/bookmarks/{id}", name="read_bookmark", methods={"GET"})
     */
    public function readBookmark($id = "", ManagerRegistry $doctrine, UrlHelper $urlHelper, Request $request): JsonResponse
    {
        $response = new JsonResponse();
        $response->headers->set("Server", "BookmarkAPI");

        $bookmark = ($request->attributes->get("_route")) === "read_last_bookmark" ? $doctrine->getRepository(Bookmark::class)->findLastEntry() : $doctrine->getRepository(Bookmark::class)->find($id);

        if (!$bookmark) {
            throw $this->createNotFoundException("No bookmark found for id $id");
        }

        $name = $bookmark->getName();
        $description = $bookmark->getDescription();
        $url = $bookmark->getUrl();
        $id = $bookmark->getId();

        $baseUrl = $urlHelper->getAbsoluteUrl("/api/bookmarks");
        $response->headers->set("Link", "<$baseUrl/api/bookmarks/$id/qrcode>; title=\"QR Code\"; type=\"image/png\"");
        $response->headers->set("Link", "<$baseUrl>; rel=\"related\"; title=\"Bookmarked link\"", false); // false pour pas écraser le Link précédent
        $response->headers->set("Link", "<$baseUrl/api/bookmarks>; rel=\"collection\"", false); // false pour pas écraser le Link précédent

        $response->setVary("Accept");

        $response->setCache([
            "last_modified" => $bookmark->getLastupdate(),
            "etag" => sha1($response->getContent()),
            "max_age" => 60,
            "public" => true
        ]);
        $response->isNotModified($request);

        $response->setData([
            "name" => $name,
            "description" => $description,
            "url" => $url
        ]);

        return $response;
    }

    /**
     * @Route("/api/bookmarks/{id}", name="update_bookmark", methods={"PUT"})
     */
    public function updateBookmark(ManagerRegistry $doctrine, Request $request, $id): Response
    {
        $response = new Response();
        $response->headers->set("Server", "BookmarkAPI");

        $entityManager = $doctrine->getManager();
        $bookmark = $entityManager->getRepository(Bookmark::class)->find($id);

        if (!$bookmark) {
            $response->setStatusCode(Response::HTTP_NOT_FOUND, "Not Found");
            $response->setContent("Wrong bookmark id!");
           return $response;
        }

        $name = $request->get("name");
        $description = $request->get("description");
        $url = $request->get("url");
        $bookmark->setLastupdate(new \DateTime('now', new \DateTimeZone(date_default_timezone_get())));

        if (!isset($name) AND !isset($description) AND !isset($url)) {
            $response->setStatusCode(Response::HTTP_BAD_REQUEST, "Bad Request");
            $response->setContent("Your request is empty!");
            return $response;
        }

        if ($name) {
            $bookmark->setName($name);
        }
        if ($description) {
            $bookmark->setDescription($description);
        }
        if ($url) {
            $bookmark->setUrl($url);
        }

        $entityManager = $doctrine->getManager();
        $entityManager->flush();

        $response->setStatusCode(Response::HTTP_OK, "Ok");
        $response->setContent("Content updated");

        return $response;
    }

    /**
     * @Route("/api/bookmarks", name="not_allowed_method")
     */
    public function notAllowedMethod(): Response
    {
        $response = new Response();
        $response->headers->set("Server", "BookmarkAPI");
        $response->setStatusCode(Response::HTTP_METHOD_NOT_ALLOWED, "Method Not Allowed");
        $response->setContent("Method Not Allowed");
        $response->headers->set("Allow", "POST, GET");

        return $response;
    }
}
