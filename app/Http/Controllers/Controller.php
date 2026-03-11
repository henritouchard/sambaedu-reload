<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use OpenApi\Attributes as OA;

#[OA\Info(
    version: "1.0.0",
    title: "SambaEdu API",
    description: "API REST pour l'application SambaEdu - Gestion des services éducatifs"
)]
#[OA\Server(
    url: "/api/v1",
    description: "Serveur API SambaEdu v1"
)]
#[OA\Tag(
    name: "health",
    description: "Endpoints de vérification de santé du système"
)]
#[OA\Tag(
    name: "users",
    description: "Gestion des utilisateurs SambaEdu"
)]
#[OA\Tag(
    name: "admin",
    description: "Endpoints d'administration nécessitant des privilèges élevés"
)]
#[OA\Tag(
    name: "ecowatt",
    description: "Service de consultation des données EcoWatt (RTE)"
)]
#[OA\Tag(
    name: "checkhub",
    description: "Endpoints utilisés par le Check Hub (clé API)"
)]
#[OA\SecurityScheme(
    securityScheme: "sambaedu_auth",
    type: "apiKey",
    description: "Authentification via cookies de session SambaEdu",
    name: "Cookie",
    in: "header"
)]
#[OA\SecurityScheme(
    securityScheme: "checkhub_api_key",
    type: "apiKey",
    description: "Clé API Check Hub. À fournir de préférence dans l'en-tête X-Api-Key. Alternative possible: Authorization: ApiKey <clé>",
    name: "X-Api-Key",
    in: "header"
)]
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}
