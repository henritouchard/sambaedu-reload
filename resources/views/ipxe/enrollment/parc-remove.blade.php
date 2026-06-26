{!! $shebang !!}
{{-- Story 4.10 (correctif review #2) propagation auth iPXE iso `admin.blade.php`. --}}
params
{{-- Variables iPXE SMBIOS (cf. name.blade.php / room.blade.php) - pas Laravel. --}}
param mac ${net0/mac}
param uuid ${uuid}
param product ${product}
param username ${username}
param password ${password:base64}
console --x {{ $resolutionX }} --y {{ $resolutionY }} --picture {{ $resolutionPng }} ||
@if($detachedParcName !== null)
echo La machine a ete enlevee du parc {{ $detachedParcName }}
sleep 3
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params
@elseif($failed)
echo ERREUR la machine n'a pas ete enlevee du parc
sleep 3
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params
@else
:menu
menu Retrait d'un parc pour {{ $workstationName }}
set menu-default fin
set menu-timeout {{ $menuTimeoutMs }}
@foreach($currentParcs as $parc)
item p-{{ $parc['id'] }} {{ $parc['display_name'] }}
@endforeach
item fin Retour au menu principal
choose --default ${menu-default} --timeout ${menu-timeout} selected && goto ${selected} || exit 0
@foreach($currentParcs as $parc)
:p-{{ $parc['id'] }}
set parc {{ $parc['id'] }}
goto suite
@endforeach
:fin
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params
:suite
param parc ${parc}
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/enrollment/parc-remove##params
@endif
