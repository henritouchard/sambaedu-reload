{!! $shebang !!}
console --x {{ $resolutionX }} --y {{ $resolutionY }} --picture {{ $resolutionPng }}
:menu
menu Preboot eXecution Environment for {{ $workstationName }} ({{ $ip }})
set menu-default {{ $menuDefault }}
set menu-timeout {{ $menuTimeoutMs }}
item --gap -- ----------------------------------------------------------------------
item --key 1 login (1) Acces au menu d'administration
@if($action)
item --key 2 action (2) Action programmee : {{ $action['label'] ?? 'pending' }}
@endif
item --key 3 default (3) Quitter iPXE et booter le disque dur
choose --default ${menu-default} --timeout ${menu-timeout} selected && goto ${selected} || exit 0

:login
login
isset ${username} && isset ${password} || goto menu
params
param mac ${net0/mac}
param uuid {{ $uuid }}
param username ${username}
param password ${password:base64}
param ${platform}
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params
 || sleep 10

@if($action)
:action
params
param mac ${net0/mac}
param uuid {{ $uuid }}
param action {{ $action['name'] ?? '' }}
param ${platform}
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/action.php##params
 || sleep 10
@endif

:default
echo Demarrage sur les disques locaux...
echo Boot Disque 1...
{!! $bootDiskFallback !!}
