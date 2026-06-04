{!! $shebang !!}
{{-- Story 4.10 propagation auth iPXE. Cf. PHPDoc IpxeAuthService. --}}
params
param mac ${net0/mac}
param uuid ${uuid}
param username ${username}
param password ${password:base64}
console --x {{ $resolutionX }} --y {{ $resolutionY }} --picture {{ $resolutionPng }} ||
:menu
menu Preboot eXecution Environment pour {{ $workstationName }} ({{ $ip }})
set menu-default exit
set menu-timeout {{ $menuTimeoutMs }}
item --gap -- ----------------------------------------------------------------------
@if($isKnown)
@if($isEnrollmentActive)
item --key n set-name (n) Renommer le poste : {{ $workstationName }}
item --key a salle (a) Affecter a une salle physique
item --key p parcs (p) Ajouter a un parc logique
item --key e enleveparc (e) Retirer d'un parc logique
@endif
@if($isInstallLinuxActive)
item --key l install-linux (l) Installation Linux (Debian/Ubuntu)
@endif
@if($isInstallWindowsActive)
item --key w install-windows (w) Installation Windows (Win10/Win11)
@endif
item --key m maintenance (m) Outils de maintenance (rescuecd, winpe, factory reset)
@else
@if($isEnrollmentActive)
item --key n set-name (n) Nommer le poste (enregistrement)
@else
echo Poste non enregistre, enrollment desactive (voir admin SE5).
sleep 3
@endif
@endif
item --key r retour (r) Retour au menu de boot
item --key s shell (s) iPXE shell
item --key x exit (x) Quitter iPXE et booter le disque dur
choose --default ${menu-default} --timeout ${menu-timeout} selected && goto ${selected} || exit 0

:set-name
chain --replace --autofree {{ $enrollmentBaseUrl }}/name##params

:salle
chain --replace --autofree {{ $enrollmentBaseUrl }}/room##params

:parcs
chain --replace --autofree {{ $enrollmentBaseUrl }}/parc-add##params

:enleveparc
chain --replace --autofree {{ $enrollmentBaseUrl }}/parc-remove##params

:install-linux
chain --replace --autofree {{ $installLinuxBaseUrl }}##params

:install-windows
chain --replace --autofree {{ $installWindowsBaseUrl }}##params

:maintenance
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/maintenance##params

:retour
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/boot##params

:shell
echo iPXE shell...
shell

:exit
echo Demarrage sur les disques locaux...
{!! $bootDiskFallback !!}
