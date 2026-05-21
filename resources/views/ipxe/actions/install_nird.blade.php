{!! $shebang !!}
kernel {{ $osUrl }}{{ $kernelPath }}
initrd --name initrd.gz {{ $osUrl }}{{ $initrdPath }}
imgargs vmlinuz initrd=initrd.gz root=/dev/nfs boot=casper netboot=nfs nfsroot={{ $nfsRoot }} ip=dhcp auto=true hostname={{ $workstationName }} priority=critical auto url={!! $preseedUrl !!}
boot
