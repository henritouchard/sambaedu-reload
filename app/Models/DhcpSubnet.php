<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Story 8.3 — Modèle Eloquent d'un sous-réseau DHCP (VLAN).
 *
 * Source de vérité SER pour les sous-réseaux/VLAN DHCP gérés. L'export vers
 * `/etc/sambaedu/sambaedu.conf.d/dhcp-subnets.conf` est dérivé de cette table
 * par `DhcpSubnetService::exportSubnetsFile()` à chaque mutation, puis
 * consommé par `make_dhcpd_conf.sh` (boucle `config_dhcp_reseau_$i`).
 *
 * Le sous-réseau **par défaut** (VLAN 0) n'est PAS modélisé ici : il vit dans
 * `dhcp.conf` (clés `dhcp_reseau`, `dhcp_masque`, …) et reste géré par
 * l'autoconf serveur (lecture seule côté SER, cf. décision D3 de la story).
 *
 * @property int          $id
 * @property int          $vlan_id      1..999 (contrainte D4 : générateur < 1024)
 * @property string       $network      CIDR complet normalisé (ex. `192.168.20.0/24`)
 * @property string       $gateway      IPv4 de la passerelle du VLAN
 * @property array<int,array{begin:string,end:string}> $ranges  Plages dynamiques (min 1)
 * @property string|null  $extra_option Chemin d'un fichier d'options DHCP (sans espace)
 * @property string|null  $description
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class DhcpSubnet extends Model
{
    use HasFactory;

    /** Borne haute du n° de VLAN (générateur `make_dhcpd_conf.sh` : i < 1024). */
    public const VLAN_MIN = 1;
    public const VLAN_MAX = 999;

    protected $table = 'dhcp_subnets';

    protected $fillable = [
        'vlan_id',
        'network',
        'gateway',
        'ranges',
        'extra_option',
        'description',
    ];

    protected $casts = [
        'vlan_id' => 'integer',
        'ranges' => 'array',
    ];

    /**
     * Scope de tri stable par n° de VLAN (ordre d'émission du fichier conf).
     */
    public function scopeOrderedByVlan(Builder $query): Builder
    {
        return $query->orderBy('vlan_id');
    }
}
