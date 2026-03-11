<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\EcowattService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: "ecowatt",
    description: "Service de consultation des données EcoWatt (RTE)"
)]
class EcowattController extends Controller
{
    private $ecowattService;

    public function __construct(EcowattService $ecowattService)
    {
        $this->ecowattService = $ecowattService;
    }

    #[OA\Get(
        path: "/api/v1/ecowatt/status",
        summary: "État du réseau électrique français",
        description: "Retourne les données en temps réel du service EcoWatt de RTE sur l'état du réseau électrique français",
        tags: ["ecowatt"]
    )]
    #[OA\Response(
        response: 200,
        description: "Données EcoWatt récupérées avec succès",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                "status" => new OA\Property(property: "status", type: "string", example: "normal", description: "État général du réseau"),
                "level" => new OA\Property(property: "level", type: "integer", example: 1, description: "Niveau d'alerte (1=vert, 2=orange, 3=rouge)"),
                "message" => new OA\Property(property: "message", type: "string", example: "Consommation normale", description: "Message descriptif"),
                "timestamp" => new OA\Property(property: "timestamp", type: "string", format: "date-time", example: "2024-01-15T10:30:00Z", description: "Horodatage des données"),
                "data" => new OA\Property(
                    property: "data",
                    type: "object",
                    description: "Données détaillées du service EcoWatt",
                    properties: [
                        "region" => new OA\Property(property: "region", type: "string", example: "France", description: "Zone géographique"),
                        "forecast" => new OA\Property(
                            property: "forecast",
                            type: "array",
                            description: "Prévisions pour les prochaines heures",
                            items: new OA\Items(
                                type: "object",
                                properties: [
                                    "hour" => new OA\Property(property: "hour", type: "string", example: "14:00", description: "Heure"),
                                    "level" => new OA\Property(property: "level", type: "integer", example: 1, description: "Niveau prévu")
                                ]
                            )
                        )
                    ]
                )
            ]
        )
    )]
    #[OA\Response(
        response: 503,
        description: "Service EcoWatt indisponible",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                "error" => new OA\Property(property: "error", type: "string", example: "Service indisponible", description: "Message d'erreur")
            ]
        )
    )]
    public function status(): JsonResponse
    {
        $data = $this->ecowattService->getStatus();
        
        if ($data === null) {
            return response()->json([
                'error' => 'Service indisponible'
            ], 503);
        }

        return response()->json($data);
    }
}
