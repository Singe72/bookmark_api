<?php

namespace App\Controller;

use App\Entity\Bookmark;
use App\Form\BookmarkType;
use App\Service\Metadata\Crawler\MetadataCrawlerInterface;
use Doctrine\Persistence\ManagerRegistry;
use MetadataParserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;

class BookmarkController extends AbstractController
{
    use TraitController;

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
            "etag" => sha1($response->getContent() . $response->headers->get("X-Total-Count") . $response->headers->get("X-Current-page") . $response->headers->get("X-Page-Size")),
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

        $entityManager = $doctrine->getManager();

        $bookmark = new Bookmark();
        $bookmark->setLastupdate(new \DateTime("now", new \DateTimeZone(date_default_timezone_get())));

        $form = $this->createBookmarkForm($bookmark);
        $form->submit($this->getRequestData($request));

        if (!$form->isValid()) {
            $errors = $form->getErrors(true);
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return new JsonResponse($errorMessages, Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();
        if ($user) {
            $bookmark->setUser($user);
        }

        $entityManager->persist($form->getData());
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
            "etag" => sha1($response->getContent() . $id),
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

        $form = $this->createForm(BookmarkType::class, $bookmark);

        $form->submit($this->getRequestData($request));

        if (!$form->isValid()) {
            $errors = $form->getErrors(true);
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return new JsonResponse($errorMessages, Response::HTTP_BAD_REQUEST);
        }

        $bookmark->setLastupdate(new \DateTime("now", new \DateTimeZone(date_default_timezone_get())));

        /* $name = $this->getRequestData($request)["name"];
        $description = $this->getRequestData($request)["description"];
        $url = $this->getRequestData($request)["url"];
        $bookmark->setLastupdate(new \DateTime("now", new \DateTimeZone(date_default_timezone_get())));

        if ($name) {
            $bookmark->setName($name);
        }
        if ($description) {
            $bookmark->setDescription($description);
        }
        if ($url) {
            $bookmark->setUrl($url);
        }

        $errors = $validator->validate($bookmark);

        if (count($errors) > 0) {
            $errorsString = (string) $errors;
            $response->setStatusCode(Response::HTTP_BAD_REQUEST, "Bad Request");
            $response->setContent($errorsString);
            return $response;
        } */

        $entityManager = $doctrine->getManager();
        $entityManager->flush();

        $response->setStatusCode(Response::HTTP_OK, "Ok");
        $response->setContent("Content updated");

        return $response;
    }

    /**
     * @Route("/api/bookmarks/{id}/metadata", name="read_bookmark_metadata", methods={"GET"})
     */
    /* public function metadata($id = "", ManagerRegistry $doctrine, UrlHelper $urlHelper, Request $request, MetadataCrawlerInterface $crawler): JsonResponse
    {
        $response = new JsonResponse();
        $response->headers->set("Server", "BookmarkAPI");

        $bookmark = $doctrine->getRepository(Bookmark::class)->find($id);

        if (!$bookmark) {
            throw $this->createNotFoundException("No bookmark found for id $id");
        }

        $url = $bookmark->getUrl();
        $id = $bookmark->getId();

        $metadata = $crawler->getContent($url);

        $baseUrl = $urlHelper->getAbsoluteUrl("/api/bookmarks");
        $response->headers->set("Link", "<$baseUrl/api/bookmarks/$id/qrcode>; title=\"QR Code\"; type=\"image/png\"");
        $response->headers->set("Link", "<$baseUrl>; rel=\"related\"; title=\"Bookmarked link\"", false); // false pour pas écraser le Link précédent
        $response->headers->set("Link", "<$baseUrl/api/bookmarks>; rel=\"collection\"", false); // false pour pas écraser le Link précédent
        $response->headers->set("Link", "<$baseUrl/api/bookmarks/$id/metadata>; title=\"Metadata\"; type=\"application/json\"", false); // false pour pas écraser le Link précédent

        $response->setVary("Accept");

        $response->setCache([
            "last_modified" => $bookmark->getLastupdate(),
            "etag" => sha1($response->getContent() . $id),
            "max_age" => 60,
            "public" => true
        ]);
        $response->isNotModified($request);

        $response->setData($metadata);

        $response->setStatusCode(Response::HTTP_OK, "Ok");
        return $response;
    } */

    public function metadata($id = "", ManagerRegistry $doctrine, UrlHelper $urlHelper, Request $request, MetadataCrawlerInterface $crawler, MetadataParserInterface $parser): JsonResponse
    {
        $response = new JsonResponse();
        $response->headers->set("Server", "BookmarkAPI");

        $bookmark = $doctrine->getRepository(Bookmark::class)->find($id);

        if (!$bookmark) {
            throw $this->createNotFoundException("No bookmark found for id $id");
        }

        $url = $bookmark->getUrl();
        $id = $bookmark->getId();

        $content = $crawler->getContent($url);
        $metadata = $parser->getMetadata($url, strval($content));

        $baseUrl = $urlHelper->getAbsoluteUrl("/api/bookmarks");
        $response->headers->set("Link", "<$baseUrl/api/bookmarks/$id/qrcode>; title=\"QR Code\"; type=\"image/png\"");
        $response->headers->set("Link", "<$baseUrl>; rel=\"related\"; title=\"Bookmarked link\"", false); // false pour pas écraser le Link précédent
        $response->headers->set("Link", "<$baseUrl/api/bookmarks>; rel=\"collection\"", false); // false pour pas écraser le Link précédent
        $response->headers->set("Link", "<$baseUrl/api/bookmarks/$id/metadata>; title=\"Metadata\"; type=\"application/json\"", false); // false pour pas écraser le Link précédent

        $response->setVary("Accept");

        $response->setCache([
            "last_modified" => $bookmark->getLastupdate(),
            "etag" => sha1($response->getContent() . $id),
            "max_age" => 60,
            "public" => true
        ]);
        $response->isNotModified($request);

        $response->setData($metadata);

        $response->setStatusCode(Response::HTTP_OK, "Ok");
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

    private function createBookmarkForm(Bookmark $bookmark)
    {
        $form = $this->createFormBuilder($bookmark, ["csrf_protection" => false])
            ->add("name", TextType::class)
            ->add("description", TextareaType::class)
            ->add("url", UrlType::class)
            ->getForm();

        return $form;
    }
}
