<?php

declare(strict_types=1);

namespace App\Services\Ldap;

use App\Config\LdapDnHelper;
use App\Facades\SEConfig;
use Illuminate\Support\Facades\Log;
use LdapRecord\Container;

/**
 * Résout l'ensemble des postes AD rattachés à un établissement.
 *
 * Utilisé par les étapes d'import qui n'ont pas de rattachement direct étab
 * (OU groupes physiques, parcs / AppProfiles) : on déduit l'appartenance par
 * intersection avec les postes de l'établissement.
 *
 * La résolution est mise en cache par DN établissement pendant la durée de vie
 * de l'instance (évite de re-requêter le LDAP entre étapes d'un même run).
 */
final class EstablishmentWorkstationScope
{
    /** @var array<string,array{dns: array<int,string>, ou_names: array<int,string>}> */
    private array $cache = [];

    public function __construct(private readonly LdapDnHelper $dnHelper) {}

    /**
     * Liste les DN (lowercased) des postes appartenant à l'établissement.
     *
     * @return array<int,string>
     */
    public function workstationDns(?string $establishmentDn): array
    {
        return $this->resolve($establishmentDn)['dns'];
    }

    /**
     * Liste les noms d'OU (lowercased) qui contiennent — directement ou via
     * un ancêtre — au moins un poste de l'établissement.
     *
     * @return array<int,string>
     */
    public function parentOuNames(?string $establishmentDn): array
    {
        return $this->resolve($establishmentDn)['ou_names'];
    }

    /**
     * Indique si l'objet (poste ou groupe) doit être conservé pour cet étab,
     * d'après son DN — utilisé pour les `member` des parcs (DNs de machines).
     */
    public function dnBelongsToEstablishment(string $dn, ?string $establishmentDn): bool
    {
        if ($establishmentDn === null) {
            return true;
        }

        return in_array(strtolower(trim($dn)), $this->workstationDns($establishmentDn), true);
    }

    /**
     * @return array{dns: array<int,string>, ou_names: array<int,string>}
     */
    private function resolve(?string $establishmentDn): array
    {
        if ($establishmentDn === null || trim($establishmentDn) === '') {
            return ['dns' => [], 'ou_names' => []];
        }

        $key = strtolower(trim($establishmentDn));
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $computersDn = $this->dnHelper->computers();

        try {
            $connection = Container::getDefaultConnection();
            $machines = $connection->query()
                ->in($computersDn)
                ->rawFilter('(objectclass=computer)')
                ->select(['cn', 'memberof'])
                ->get();
        } catch (\Throwable $e) {
            Log::error('EstablishmentWorkstationScope: échec requête postes AD', [
                'establishment_dn' => $establishmentDn,
                'error' => $e->getMessage(),
            ]);

            return $this->cache[$key] = ['dns' => [], 'ou_names' => []];
        }

        $dns = [];
        $ouNames = [];
        $computersDnLower = strtolower(trim($computersDn));

        foreach ($machines as $machine) {
            $dn = strtolower(trim((string) ($machine['dn'] ?? '')));
            if ($dn === '') {
                continue;
            }

            $memberOf = is_array($machine['memberof'] ?? null) ? $machine['memberof'] : [];
            $match = EstablishmentMatcher::match($dn, $memberOf, $establishmentDn);
            if ($match === null) {
                continue;
            }

            $dns[] = $dn;

            // Remonte la chaîne d'OU ancêtres jusqu'à (et incluant) OU=Computers.
            foreach ($this->ancestorOuNames($dn, $computersDnLower) as $ouName) {
                $ouNames[$ouName] = true;
            }
        }

        return $this->cache[$key] = [
            'dns' => array_values(array_unique($dns)),
            'ou_names' => array_values(array_keys($ouNames)),
        ];
    }

    /**
     * Extrait les noms d'OU dans la chaîne parente du DN d'un poste,
     * jusqu'à (et incluant) la racine OU=Computers.
     *
     * Entrée :  cn=pc-1,ou=salle-a,ou=batiment-b,ou=computers,dc=...
     * Sortie : ['salle-a', 'batiment-b', 'computers']
     *
     * @return array<int,string>
     */
    private function ancestorOuNames(string $machineDn, string $computersDnLower): array
    {
        if (! str_ends_with($machineDn, $computersDnLower)) {
            return [];
        }

        $middle = substr($machineDn, 0, -strlen($computersDnLower));
        $middle = preg_replace('/^cn=[^,]+,/i', '', $middle) ?? '';
        $middle = rtrim($middle, ',');

        $names = [];
        if ($middle !== '') {
            foreach (explode(',', $middle) as $rdn) {
                if (preg_match('/^ou=(.+)$/i', $rdn, $m)) {
                    $names[] = strtolower($m[1]);
                }
            }
        }

        if (preg_match('/^ou=([^,]+),/i', $computersDnLower, $m)) {
            $names[] = strtolower($m[1]);
        }

        return $names;
    }
}
