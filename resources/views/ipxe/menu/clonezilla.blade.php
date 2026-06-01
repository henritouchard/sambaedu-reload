{!! $shebang !!}
{{-- Story 4.10  propagation auth iPXE iso `admin.blade.php`. --}}
params
{{-- Variables iPXE SMBIOS (cf. name.blade.php / installation-linux.blade.php). --}}
param mac ${net0/mac}
param uuid ${uuid}
param username ${username}
param password ${password:base64}
console --x {{ $resolutionX }} --y {{ $resolutionY }} --picture {{ $resolutionPng }}
:menu
menu Clonezilla - {{ $workstationName }} ({{ $ip }})
set menu-default exit
set menu-timeout {{ $menuTimeoutMs }}
item --gap -- ----------------------------------------------------------------------
item --key l clonezilla_live (l) Clonezilla LiveCD (mode interactif)
item --key s clonezilla_save (s) Sauvegarde locale sda1 vers sda2
item --key r clonezilla_restore (r) Restauration locale sda2 vers sda1
item --gap -- ----------------------------------------------------------------------
item --key b retour (b) Retour au menu maintenance
item --key x exit (x) Boot sur disque dur
choose --default ${menu-default} --timeout ${menu-timeout} selected && goto ${selected} || exit 0

:clonezilla_live
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/action/clonezilla_live##params

:clonezilla_save
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/action/clonezilla_save_sda1_sda2##params

:clonezilla_restore
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/action/clonezilla_restore_sda2_sda1##params

:retour
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/maintenance##params

:exit
echo Demarrage sur les disques locaux...
{!! $bootDiskFallback !!}
