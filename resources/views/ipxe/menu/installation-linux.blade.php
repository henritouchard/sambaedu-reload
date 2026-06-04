{!! $shebang !!}
{{-- Story 4.10  propagation auth iPXE iso `admin.blade.php`. --}}
params
{{-- Variables iPXE SMBIOS (cf. name.blade.php) - pas les valeurs Laravel : un
     uuid SQL vide ferait basculer /ipxe/action sur le handshake -> chemin
     relatif double -> 404 -> reboot PXE. --}}
param mac ${net0/mac}
param uuid ${uuid}
param username ${username}
param password ${password:base64}
console --x {{ $resolutionX }} --y {{ $resolutionY }} --picture {{ $resolutionPng }} ||
@if($isKnown)
:menu
menu installation clients-linux pour {{ $workstationName }} ({{ $ip }})
set menu-default {{ $defaultVariant }}
set menu-timeout {{ $menuTimeoutMs }}
item --gap -- ----------------------------------------------------------------------
@foreach($installLinuxItems as $item)
item {{ $item['enum'] }} {{ $item['label'] }}
@endforeach
item --gap -- ----------------------------------------------------------------------
item --key s shell (s) iPXE shell
item --key r retour (r) Retour au menu admin
item --key x exit (x) Boot sur disque dur
choose --default ${menu-default} --timeout ${menu-timeout} selected && goto ${selected} || exit 0

@foreach($installLinuxItems as $item)
:{{ $item['enum'] }}
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/action/{{ $item['enum'] }}##params

@endforeach
:retour
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params

:shell
echo iPXE shell...
shell

:exit
echo Demarrage sur les disques locaux...
{!! $bootDiskFallback !!}
@else
echo Erreur - poste non encore enregistre
echo Utilisez (n) Nommer le poste depuis /ipxe/admin avant d'installer un OS.
sleep 5
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params
@endif
