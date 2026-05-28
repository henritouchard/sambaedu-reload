{!! $shebang !!}
params
param mac {{ $mac }}
param uuid {{ $uuid }}
param platform {{ $platform }}
@if($result === null)
echo === DEBUG enrollment/name handshake ===
echo mac={{ $mac }} uuid={{ $uuid }} platform={{ $platform }}
echo currentName={{ $currentName }}
echo serverBaseUrl={{ $serverBaseUrl }}
prompt --timeout 15000 Appuyez sur une touche pour continuer (15s)...
echo Entrez le nom de la machine:
set name {{ $currentName }}
read name
echo Vous avez saisi : ${name}
param new_name ${name}
prompt --timeout 5000 Chain vers serveur dans 5s, appuyez sur une touche pour annuler...
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
