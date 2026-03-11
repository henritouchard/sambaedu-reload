<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

class EcowattService
{
    private $client;
    private $config;

    public function __construct()
    {
        // TODO: Injecter la config via le service provider
        $this->config = config('sambaedu');
        $this->client = new Client([
            'base_uri' => 'https://digital.iservices.rte-france.com/',
            'cookies' => true
        ]);
    }

    public function getStatus()
    {
        // Utiliser le cache Laravel au lieu d'APCu
        return Cache::remember('ecowatt', 82800, function () {
            return $this->fetchEcowattData();
        });
    }

    private function fetchEcowattData()
    {
        try {
            // Obtenir le token
            $response = $this->client->request('POST', '/token/oauth/', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . config('sambaedu.rte_api_key')
                ]
            ]);

            $responseArr = json_decode($response->getBody(), true);
            $accessToken = $responseArr['access_token'];

            // Faire la requête API
            $response = $this->client->request('GET', '/open_api/ecowatt/v4/signals', [
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF-8',
                    'Authorization' => 'Bearer ' . $accessToken
                ]
            ]);

            if ($response) {
                return json_decode($response->getBody(), true)['signals'];
            }
        } catch (\Exception $e) {
            // Si l'appel à RTE échoue, on essaie le serveur central
            return $this->fetchFromCentralServer();
        }

        return null;
    }

    private function fetchFromCentralServer()
    {
        try {
            $client = new Client([
                'base_uri' => 'https://central.sambaedu.org/'
            ]);
            
            $response = $client->request('GET', 'api/ecowatt.php');
            
            if ($response) {
                return json_decode($response->getBody(), true);
            }
        } catch (\Exception $e) {
            return null;
        }
    }
} 