{!! $shebang !!}
{{-- Story 4.10 (correctif review #2) propagation auth iPXE iso `admin.blade.php`. --}}
params
{{-- Variables iPXE SMBIOS (${net0/mac}/${uuid}) et NON les valeurs Laravel :
     fournies par le firmware a chaque requete, donc toujours presentes meme si
     le poste a un uuid/mac vide ou divergent en SQL. Un uuid vide ferait
     basculer /ipxe/admin sur le preambule handshake (qui ne porte pas
     username/password) -> perte d'auth au retour menu. Iso `admin.blade.php`
     / `known.blade.php`. --}}
param mac ${net0/mac}
param uuid ${uuid}
param platform {{ $platform }}
param username ${username}
param password ${password:base64}
@if($result === null)
echo Entrez le nom de la machine:
set name {{ $currentName }}
read name
echo Vous avez saisi : ${name}
param new_name ${name}
{{-- NE PAS ajouter de `prompt` ici : un script iPXE s'interrompt (abort) des
     qu'une commande renvoie un statut non-zero sans `||`. `prompt --timeout`
     renvoie un echec a l'expiration du delai sans appui touche, ce qui faisait
     avorter le script AVANT le `chain` (aucune requete serveur, reboot PXE).
     Iso-legacy `enregistrement.php` : on enchaine directement apres `read`. --}}
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
