<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;

trait TraitController
{
    /**
     * @param Request $request Instance de Symfony\Component\HttpFoundation\Request
     * @return array Données de la requête
     */
    public function getRequestData(Request $request): array
    {
        $data = [];

        $contentType = $request->headers->get("Content-Type");

        switch ($contentType) {
            case "application/json":
                $data = json_decode($request->getContent(), true);
                break;
            case "application/x-www-form-urlencoded":
                $data = $request->request->all();
                break;
        }

        return $data;
    }
}
