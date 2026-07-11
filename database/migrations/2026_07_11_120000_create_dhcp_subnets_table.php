<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 8.3 — Création de la table `dhcp_subnets` (sous-réseaux / VLAN DHCP).
 *
 * Modèle de données dédié (décision D1) : SQL = source de vérité, l'export
 * `/etc/sambaedu/sambaedu.conf.d/dhcp-subnets.conf` en est dérivé et consommé
 * par `make_dhcpd_conf.sh`. Le sous-réseau PAR DÉFAUT (VLAN 0) n'est PAS stocké
 * ici — il vit dans `dhcp.conf` (lecture seule côté SER, décision D3).
 *
 * Choix `string` pour `network` / `gateway` (vs `inet`/`cidr` PostgreSQL) :
 *  - portabilité driver test (SQLite) ;
 *  - la cohérence réseau (CIDR, passerelle ⊂ réseau, non-chevauchement) reste
 *    applicative (`DhcpSubnetService`).
 *
 * `ranges` en JSON (`[{"begin":"…","end":"…"}, …]`, min 1) : les plages
 * dynamiques multiples sont émises en `dhcp_begin_range_<N>` +
 * `dhcp_begin_range_<N>_<j>` (j contigu dès 1) — capacité multi-plages que le
 * générateur legacy sait déjà consommer mais que l'UI legacy n'exposait pas.
 *
 * Contraintes :
 *  - `vlan_id` UNIQUE, borné 1..999 (D4 — générateur `i < 1024`, legacy 3 chiffres) ;
 *  - `network` string/45 (CIDR complet, IPv6 future) ;
 *  - `gateway` string/45.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dhcp_subnets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('vlan_id');       // borné 1..999 côté service
            $table->string('network', 45);            // CIDR complet, ex. 192.168.20.0/24
            $table->string('gateway', 45);            // IPv4 passerelle
            $table->json('ranges');                   // [{begin,end}, …] min 1
            $table->string('extra_option', 255)->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique('vlan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dhcp_subnets');
    }
};
