{!! $shebang !!}
{{-- Story 4.10 (correctif review #2) propagation auth iPXE iso `admin.blade.php`. --}}
params
{{-- Variables iPXE SMBIOS (cf. name.blade.php) - pas les valeurs Laravel :
     un uuid vide en SQL ferait basculer /ipxe/admin au retour sur le handshake
     (sans creds) -> "identifiants invalides" + redirection boot. --}}
param mac ${net0/mac}
param uuid ${uuid}
param product ${product}
param username ${username}
param password ${password:base64}
console --x {{ $resolutionX }} --y {{ $resolutionY }} --picture {{ $resolutionPng }} ||
@if($assignedRoomName !== null)
echo La machine a ete ajoutee a la salle {{ $assignedRoomName }}
sleep 3
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params
@elseif($failed)
echo ERREUR la machine n'a pas ete affectee a la salle
sleep 3
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params
@else
:menu
menu Enregistrement de la salle pour {{ $workstationName }}
set menu-default fin
set menu-timeout {{ $menuTimeoutMs }}
@foreach($availableRooms as $room)
@if($room['is_current'])
item fin ** deja dans {{ $room['name'] }} **
@else
item r-{{ $room['id'] }} {{ $room['display_name'] }}
@endif
@endforeach
@if($truncated)
item fin ** voir UI admin SE5 (cap atteinte) **
@endif
item fin Retour au menu principal
choose --default ${menu-default} --timeout ${menu-timeout} selected && goto ${selected} || exit 0
@foreach($availableRooms as $room)
@if(! $room['is_current'])
:r-{{ $room['id'] }}
set room {{ $room['id'] }}
goto suite
@endif
@endforeach
:fin
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params
:suite
param room ${room}
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/enrollment/room##params
@endif
