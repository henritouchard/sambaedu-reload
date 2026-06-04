{!! $shebang !!}
console --x {{ $resolutionX }} --y {{ $resolutionY }} --picture {{ $resolutionPng }} ||
:menu
menu Preboot eXecution Environment for {{ $ip }}
set menu-default exit
set menu-timeout {{ $menuTimeoutMs }}
item --gap -- ----------------------------------------------------------------------
@if($isAdminActive)
item --key 1 login (1) Acces au menu d'administration
@endif
item --key 0 exit (0) Quitter iPXE et booter le disque dur
choose --default ${menu-default} --timeout ${menu-timeout} selected && goto ${selected} || exit 0

{{-- Parite legacy boot.php:82 : l'item admin est propose MEME pour une machine
     inconnue (non enrolee), sinon impossible de l'enroler/installer via iPXE
     (la machine neuve n'atteindrait jamais /ipxe/admin). menu-default reste
     `exit` -> auto-boot disque apres timeout pour les postes non interactifs.
     L'auth reelle est cote serveur (IpxeAuthService) : un login invalide tombe
     sur auth_failed puis revient au boot. --}}
:login
login
isset ${username} && isset ${password} || goto menu
params
param mac ${net0/mac}
param uuid ${uuid}
param username ${username}
param password ${password:base64}
param platform ${platform}
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params
 || sleep 10

:exit
echo Demarrage sur les disques locaux...
echo Boot Disque 1...
{!! $bootDiskFallback !!}
