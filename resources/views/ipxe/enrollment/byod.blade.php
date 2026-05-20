{!! $shebang !!}
params
param mac {{ $mac }}
param uuid {{ $uuid }}
param platform {{ $platform }}
console --x {{ $resolutionX }} --y {{ $resolutionY }} --picture {{ $resolutionPng }}
@if($denied ?? false)
echo ERREUR ! acces refuse
sleep 3
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/boot##params
@elseif(! $logged)
echo BYOD : Enregistrement du nom pour uuid: {{ $uuid }}
echo -n Entrez le nom de la machine:
set name {{ $currentName }}
read name
param new_name ${name}
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/enrollment/byod##params
@else
echo BYOD enregistre pour {{ $sanitizedName }} ({{ $uuid }})
echo Enregistrement BYOD effectue - retour au menu principal...
sleep 3
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params
@endif
