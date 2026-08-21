<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance amont des assignations, sur les quatre supports que le contrat pose.
 *
 * Le contrat réconcilie un désir d'état : ce qu'il ne demande plus, il le retire.
 * Sans marqueur de provenance, ce retrait emporterait les assignations posées à la
 * main par l'administrateur — un parc perdrait une application qu'il n'a jamais
 * reçue du contrat. La colonne borne le prune à ce que le contrat a lui-même écrit.
 *
 * Le défaut `false` vaut pour tout l'existant : rien de ce qui a été assigné avant
 * ce jour n'entre dans le périmètre du contrat.
 */
return new class extends Migration
{
    /** Supports d'assignation que le contrat amont peut poser. */
    private const TABLES = [
        'application_workstation_group',
        'shortcut_assignables',
        'capability_assignments',
        'wallpapers',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'managed_by_control_hub')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->boolean('managed_by_control_hub')->default(false)
                    ->comment('Assignation posée par le contrat amont (seule candidate à son prune)');
                $blueprint->index('managed_by_control_hub', substr($table, 0, 40).'_ch_origin_index');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'managed_by_control_hub')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex(substr($table, 0, 40).'_ch_origin_index');
                $blueprint->dropColumn('managed_by_control_hub');
            });
        }
    }
};
