{!! $shebang !!}
console --x {{ $resolutionX }} --y {{ $resolutionY }} --picture {{ $resolutionPng }}
{{-- Fix install-debian : ecran one-shot post-install Linux. Charset ASCII
     strict (le firmware iPXE corrompt l'affichage sur les octets UTF-8 > 127,
     cf. iso D8) -> pas d'accents. Le compte a rebours natif iPXE est affiche
     par `choose --timeout` ; l'item --gap rappelle la duree en clair. --}}
:menu
menu Installation Linux terminee avec succes
item --gap -- ----------------------------------------------------------------------
item --gap -- Poste : {{ $workstationName }}
item --gap -- L'installation s'est terminee avec succes.
item --gap -- Demarrage du systeme sur le disque local dans {{ $countdownSeconds }}s...
item --gap -- ----------------------------------------------------------------------
item boot Demarrer le systeme maintenant
choose --default boot --timeout {{ $countdownMs }} selected && goto ${selected} || goto boot

:boot
echo Demarrage sur le disque local...
{!! $bootDiskFallback !!}
