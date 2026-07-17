{!! $shebang !!}
console --x {{ $resolutionX }} --y {{ $resolutionY }} --picture {{ $resolutionPng }} ||
:menu
menu Preboot eXecution Environment for {{ $workstationName }} ({{ $ip }})
set menu-default {{ $menuDefault }}
set menu-timeout {{ $menuTimeoutMs }}
item --gap -- ----------------------------------------------------------------------
@if($isAdminActive)
item --key 1 login (1) Acces au menu d'administration
@endif
{{-- Story 4.10 : `isAdminActive` reste un flag config (`ipxe.admin.enabled`)
     mais default = true depuis 4.10. L'auth est dsormais gre ct serveur
     (IpxeAuthService::authorize)  un login random est rejet avec l'cran
     `auth_failed.blade.php` puis chain back boot. --}}
@if($action)
item --key 2 action (2) Action programmee : {{ $action['label'] ?? 'pending' }}
@endif
item --key 3 default (3) Quitter iPXE et booter le disque dur
choose --default ${menu-default} --timeout ${menu-timeout} selected && goto ${selected} || exit 0

:login
login
isset ${username} && isset ${password} || goto menu
params
{{-- Toujours utiliser les variables iPXE (${net0/mac}, ${uuid}, ${platform})
     plutot que les valeurs Laravel-rendues : iPXE les fournit depuis le
     firmware (SMBIOS) a chaque requete, donc disponibles meme si le poste
     n'est pas (encore) en DB. Note iso-legacy : `sambaedu/ipxe/boot.php`
     utilisait `$uuid` PHP-rendu mais celui-ci venait du POST iPXE, donc
     equivalent a ${uuid}. --}}
param mac ${net0/mac}
param uuid ${uuid}
param product ${product}
param username ${username}
param password ${password:base64}
param platform ${platform}
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params
 || sleep 10

@if($action)
:action
params
param mac ${net0/mac}
{{-- Story 3.11 / S1 - variable SMBIOS firmware ${uuid} (jamais la valeur
     Laravel-rendue) : iso bloc `:login` + contrat project_ipxe_param_use_smbios_vars.
     Un ${uuid} vide cote firmware ferait perdre l'auth/locate cote serveur. --}}
param uuid ${uuid}
param product ${product}
param platform ${platform}
{{-- Story 3.11 / D7 - chain vers la route NATIVE /ipxe/action/{action}
     (param action dans le PATH, pas en query) au lieu du tombstone legacy
     action.php. URL absolue (jamais relative) : un chain relatif depuis
     /ipxe/boot doublerait le chemin (404, abort firmware). --}}
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/action/{{ $action['name'] ?? '' }}##params
 || sleep 10
@endif

:default
echo Demarrage sur les disques locaux...
echo Boot Disque 1...
{!! $bootDiskFallback !!}
