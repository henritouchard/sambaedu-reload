{!! $shebang !!}
params
param mac {{ $mac }}
param uuid {{ $uuid }}
param platform {{ $platform }}
console --x {{ $resolutionX }} --y {{ $resolutionY }} --picture {{ $resolutionPng }}
@if($result === null)
echo Enregistrement du nom pour uuid: {{ $uuid }}
echo (ne pas utiliser pour renommer sans reinstallation)
echo -n Entrez le nom de la machine:
set name {{ $currentName }}
read name
param new_name ${name}
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/enrollment/name##params
@else
@switch($result->status->value)
@case('same_name')
echo La machine est deja enregistree sous ce nom {{ $result->sanitizedName }}
sleep 3
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params
@break
@case('created')
@case('renamed')
@if($result->adResult === false)
echo OK ! nom {{ $result->sanitizedName }} reserve pour {{ $uuid }}
echo ATTENTION : sync AD echouee - verifiez avec admin SE5
sleep 5
@else
echo OK ! nom {{ $result->sanitizedName }} reserve pour {{ $uuid }}
sleep 3
@endif
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params
@break
@case('name_taken')
echo ERREUR ! nom {{ $result->sanitizedName }} indisponible: {{ $result->reasonLabel }}
sleep 5
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params
@break
@default
@if($result->sanitizedName !== '')
echo ERREUR ! enregistrement {{ $result->sanitizedName }} echoue: {{ $result->reasonLabel }}
@else
echo ERREUR ! enregistrement refuse: {{ $result->reasonLabel }}
@endif
sleep 5
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params
@endswitch
@endif
