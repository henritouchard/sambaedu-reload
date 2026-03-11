<?php

namespace App\Constants;

class Routes
{
    // Fonction helper pour ajouter des paramètres
    public static function withParams(string $route, array $params = []): string
    {
        if (empty($params)) {
            return $route;
        }
        
        $queryString = http_build_query($params);
        return $route . '?' . $queryString;
    }
    
    // Fonction helper pour les routes avec segments dynamiques
    public static function withSegments(string $route, array $segments = []): string
    {
        foreach ($segments as $key => $value) {
            $route = str_replace("{{$key}}", $value, $route);
        }
        return $route;
    }
}
