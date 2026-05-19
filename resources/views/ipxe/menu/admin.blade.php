{!! $shebang !!}
params
param mac {{ $mac }}
param uuid {{ $uuid }}
console --x {{ $resolutionX }} --y {{ $resolutionY }} --picture {{ $resolutionPng }}
:menu
menu Preboot eXecution Environment pour {{ $workstationName }} ({{ $ip }})
set menu-default exit
set menu-timeout {{ $menuTimeoutMs }}
item --gap -- ----------------------------------------------------------------------
@if($isKnown)
item --key m maintenance (m) Outils de maintenance (rescuecd, winpe, factory reset)
@else
echo Poste non enregistre, fonctions de maintenance indisponibles.
echo Story 3.3 enrollment a venir.
sleep 3
@endif
item --key r retour (r) Retour au menu de boot
item --key s shell (s) iPXE shell
item --key x exit (x) Quitter iPXE et booter le disque dur
choose --default ${menu-default} --timeout ${menu-timeout} selected && goto ${selected} || exit 0

:maintenance
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/maintenance##params

:retour
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/boot##params

:shell
echo iPXE shell...
shell

:exit
echo Demarrage sur les disques locaux...
{!! $bootDiskFallback !!}
