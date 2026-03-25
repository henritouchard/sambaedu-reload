<?php

namespace App\Http\Controllers;

use App\Services\LegacyEmbedService;
use Illuminate\Http\Request;

/**
 * Controller pour les pages legacy embarquées dans le layout SER.
 *
 * Permet de réutiliser des pages legacy existantes sans les réécrire,
 * en les affichant dans le layout Livewire (sidebar, navbar, etc.).
 *
 * Les forms legacy fonctionnent nativement (POST standard, pas Livewire).
 */
class LegacyEmbedController extends Controller
{
    public function __construct(
        private LegacyEmbedService $embedService,
    ) {}

    /**
     * Affiche une page legacy embarquée.
     */
    public function show(Request $request, string $module): mixed
    {
        try {
            $legacyHtml = $this->embedService->render($module);
        } catch (\RuntimeException $e) {
            abort(500, $e->getMessage());
        }

        return view('legacy-embed', [
            'legacyHtml' => $legacyHtml,
            'title' => $this->titleFromModule($module),
        ]);
    }

    /**
     * Déduit un titre lisible depuis le nom du module.
     */
    private function titleFromModule(string $module): string
    {
        $titles = [
            'annu2/add_group.php' => 'Création de groupe (legacy)',
            'annu/import_gpei.php' => 'Import GPEI (legacy)',
        ];

        return $titles[$module] ?? 'Module legacy';
    }
}
