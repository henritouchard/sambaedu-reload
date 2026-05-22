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
item --key z clonezilla (z) Sous-menu Clonezilla (live/sauvegarde/restauration)
@if(config('ipxe.tools.gparted.enabled', true))
item --key g gparted (g) GParted Live (partitionnement)
@endif
@if(config('ipxe.tools.hdt.enabled', true))
item --key h hdt (h) Hardware Detection Tool (diagnostic materiel)
@endif
@if(config('ipxe.tools.memtest86plus.enabled', true))
item --key t memtest (t) Memtest86+ (test memoire)
@endif
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

:clonezilla
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/clonezilla-menu##params

:gparted
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/action/gparted##params

:hdt
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/action/hdt##params

:memtest
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/action/memtest86plus##params

:retour
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params

:shell
echo iPXE shell...
shell

:exit
echo Demarrage sur les disques locaux...
{!! $bootDiskFallback !!}
