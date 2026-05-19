{!! $shebang !!}
params
param mac {{ $mac }}
param uuid {{ $uuid }}
console --x {{ $resolutionX }} --y {{ $resolutionY }} --picture {{ $resolutionPng }}
:menu
menu Maintenance pour {{ $workstationName }} ({{ $ip }})
set menu-default exit
set menu-timeout {{ $menuTimeoutMs }}
item --gap -- ----------------------------------------------------------------------
item --key c rescuecd (c) Utilisation de SystemRescueCD
item --key w winpe (w) Reparation Windows (WinPE)
item --key f factory_reset (f) ATTENTION - Restauration usine (efface le disque)
item --key s shell (s) iPXE shell
item --key r retour (r) Retour au menu admin
item --key x exit (x) Boot sur disque dur
choose --default ${menu-default} --timeout ${menu-timeout} selected && goto ${selected} || exit 0

:rescuecd
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/action/rescuecd##params

:winpe
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/action/winpe##params

:factory_reset
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/action/factory_reset##params

:retour
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params

:shell
echo iPXE shell...
shell

:exit
echo Demarrage sur les disques locaux...
{!! $bootDiskFallback !!}
