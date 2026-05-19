{!! $shebang !!}
console --x {{ $resolutionX }} --y {{ $resolutionY }} --picture {{ $resolutionPng }}
:menu
menu Preboot eXecution Environment for {{ $ip }}
set menu-default exit
set menu-timeout {{ $menuTimeoutMs }}
item --gap -- ----------------------------------------------------------------------
item --key 0 exit (0) Quitter iPXE et booter le disque dur
choose --default ${menu-default} --timeout ${menu-timeout} selected && goto ${selected} || exit 0

:exit
echo Demarrage sur les disques locaux...
echo Boot Disque 1...
{!! $bootDiskFallback !!}
