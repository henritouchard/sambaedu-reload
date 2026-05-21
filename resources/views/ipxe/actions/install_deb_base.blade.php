{!! $shebang !!}
kernel {{ $osUrl }}{{ $kernelPath }}
initrd --name initrd.gz {{ $osUrl }}{{ $initrdPath }}
imgargs linux initrd=initrd.gz auto=true hostname={{ $workstationName }} priority=critical auto url={!! $preseedUrl !!}
boot
