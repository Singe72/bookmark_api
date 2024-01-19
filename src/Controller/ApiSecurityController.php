<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ApiSecurityController extends AbstractController
{
    /**
     * @Route("/api/login", name="api_login", methods={"POST"})
     */
    public function login(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if ($user === null) {
            return $this->json([
                "message" => "missing credentials",
                "request" => json_decode($request->getContent())
            ], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            "email" => $user->getUserIdentifier(),
            "roles" => $user->getRoles()
        ]);
    }

    /**
     * @Route("/api/test_login", name="api_logtest", methods={"GET"})
     */
    public function logtest(): JsonResponse
    {
        $user = $this->getUser();

        if ($user === null) {
            return $this->json(["logged" => "no"], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return $this->json(["logged" => "yes", "username" => $user->getUserIdentifier()]);
    }

    /**
     * @Route("/api/logout", name="api_logout", methods={"GET"})
     */
    public function logout(): void
    {}

    /**
     * @Route("/api/me", name="api_me", methods={"GET"})
     */
    public function me(): Response
    {
        $user = $this->getUser();

        if ($user === null) {
            return $this->json(["logged" => "no"], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return $this->json(["logged" => "yes", "username" => $user->getUserIdentifier()]);
    }
}
