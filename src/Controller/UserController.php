<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class UserController extends AbstractController
{
    
    /**
     * @Route("/api/users", name="read_user_collection", methods={"GET"})
     * @Route("/api/users/pages/{page}", name="read_user_collection_page", methods={"GET"})
     * @Route("/api/users/pages/{page}/{step}", name="read_user_collection_page_step", methods={"GET"})
     * @Route("/api/users/last", name="read_user_collection_page_last", methods={"GET"})
     * @Route("/api/users/last/{step}", name="read_user_collection_page_last_step", methods={"GET"})
     */
    public function index(Request $request, ManagerRegistry $doctrine, UrlHelper $urlHelper, int $page = 1, int $step = 10): JsonResponse
    {
        $response = new JsonResponse();
        $response->headers->set("Server", "BookmarkAPI");

        $userRepository = $doctrine->getRepository(User::class);
        $userCount = $userRepository->countAll();

        if (in_array($request->attributes->get("_route"), ["read_user_collection_page_last", "read_user_collection_page_last_step"])) {
            $current = max(1, $userCount - $step);
        } else {
            $current = ($page - 1) * $step + 1;
        }

        $baseUrl = $urlHelper->getAbsoluteUrl($this->generateUrl("read_user_collection"));
        $users = $userRepository->findNextX($current - 1, $step);

        $responseData = [
            "Locations" => [],
            "meta" => [
                "total_count" => $userCount,
            ],
        ];

        foreach ($users as $user) {
            $responseData["Locations"][] = $baseUrl . "/" . $user->getId();
        }

        $response->headers->set("Link", "<{$baseUrl}/last>; rel=\"last\"");
        $response->headers->set("Link", "<{$baseUrl}>; rel=\"first\"", false);
        $response->headers->set("X-Total-Count", $userCount);
        $response->headers->set("X-Current-Page", intdiv($current, $step) + 1);
        $response->headers->set("X-Per-Page", $step);
        $response->headers->set("X-Page-Size", count($users));

        if ($current > $userCount) {
            $response->setStatusCode(JsonResponse::HTTP_NO_CONTENT, "Max page number reached");

            return $response;
        }

        if (!in_array($request->attributes->get("_route"), ["read_user_collection_page_last", "read_user_collection_page_last_step"])) {
            $nextPage = intdiv($current, $step) + 2;
            $response->headers->set("Link", "<{$baseUrl}/pages/{$nextPage}>; rel=\"next\"", false);
        }

        $response->setData($responseData);
        $response->setStatusCode(JsonResponse::HTTP_PARTIAL_CONTENT);

        return $response;
    }

    /**
     * @Route("/api/users", name="create_user", methods={"POST"})
     */
    public function create(ManagerRegistry $doctrine, Request $request, UrlHelper $urlHelper, UserPasswordHasherInterface $passwordHasher): JsonResponse
    {
        $response = new JsonResponse();
        $response->headers->set("Server", "BookmarkAPI");

        $parameters = json_decode($request->getContent(), true);
        $email = $parameters["email"];
        $plaintextPassword = $parameters["password"];

        if (empty($email) || empty($plaintextPassword)) {
            return $response->setData([
                "message" => "Email and password must not be empty",
                "request" => json_decode($request->getContent())
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $existingUser = $doctrine->getRepository(User::class)->findOneBy(["email" => $email]);

        if ($existingUser !== null) {
            return $response->setData([
                "message" => "User with this email already exists",
                "request" => json_decode($request->getContent())
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $entityManager = $doctrine->getManager();

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(["ROLE_USER"]);
        $user->setPassword($passwordHasher->hashPassword($user, $plaintextPassword));

        $entityManager->persist($user);
        $entityManager->flush();

        $id = $user->getId();

        $response->setStatusCode(JsonResponse::HTTP_CREATED);
        $response->headers->set("Location", $urlHelper->getAbsoluteUrl($this->generateUrl("read_user", ["id" => $id])));
        $response->setData([
            "message" => "User created",
            "location" => $urlHelper->getAbsoluteUrl($this->generateUrl("read_user", ["id" => $id]))
        ]);

        return $response;
    }

    /**
     * @Route("/api/users/{id}", name="update_user", methods={"PUT"})
     */
    public function update(ManagerRegistry $doctrine, Request $request, UserPasswordHasherInterface $passwordHasher, $id): JsonResponse
    {
        $response = new JsonResponse();
        $response->headers->set("Server", "BookmarkAPI");

        $entityManager = $doctrine->getManager();
        $user = $entityManager->getRepository(User::class)->find($id);

        if (!$user) {
            return $response->setData([
                "message" => "User with id $id not found",
                "request" => json_decode($request->getContent())
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        $parameters = json_decode($request->getContent(), true);
        $email = $parameters["email"];
        $plaintextPassword = $parameters["password"];
        $roles = $parameters["roles"];

        if (empty($email) || empty($plaintextPassword) || empty($roles)) {
            return $response->setData([
                "message" => "Email, password and roles must not be empty",
                "request" => json_decode($request->getContent())
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $allRoles = [
            "ROLE_USER",
            "ROLE_ADMIN",
            "ROLE_SUPER_ADMIN"
        ];

        foreach ($roles as $role) {
            if (!in_array($role, $allRoles)) {
                return $response->setData([
                    "message" => "Role $role does not exist",
                    "request" => json_decode($request->getContent())
                ], JsonResponse::HTTP_BAD_REQUEST);
            }
        }

        $user->setEmail($email);
        $user->setPassword($passwordHasher->hashPassword($user, $plaintextPassword));
        $user->setRoles($roles);
        
        $entityManager->flush();

        return $response->setData([
            "message" => "Content updated"
        ], JsonResponse::HTTP_OK);
    }

    /**
     * @Route("/api/users/{id}", name="read_user", methods={"GET"})
     */
    public function read(ManagerRegistry $doctrine, Request $request, $id): JsonResponse
    {
        $response = new JsonResponse();
        $response->headers->set("Server", "BookmarkAPI");

        $entityManager = $doctrine->getManager();
        $user = $entityManager->getRepository(User::class)->find($id);

        if (!$user) {
            return $response->setData([
                "message" => "User with id $id not found",
                "request" => json_decode($request->getContent())
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        $email = $user->getEmail();
        $password = $user->getPassword();
        $roles = $user->getRoles();

        return $response->setData([
            "email" => $email,
            "password" => $password,
            "roles" => $roles
        ]);
    }

    /**
     * @Route("/api/users/{id}", name="delete_user", methods={"DELETE"})
     */
    public function delete(ManagerRegistry $doctrine, Request $request, $id): JsonResponse
    {
        $response = new JsonResponse();
        $response->headers->set("Server", "BookmarkAPI");

        $entityManager = $doctrine->getManager();
        $user = $entityManager->getRepository(User::class)->find($id);

        if (!$user) {
            return $response->setData([
                "message" => "User with id $id not found",
                "request" => json_decode($request->getContent())
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        $entityManager->remove($user);
        $entityManager->flush();

        return $response->setData([
            "message" => "User deleted"
        ], JsonResponse::HTTP_NO_CONTENT);
    }
}
