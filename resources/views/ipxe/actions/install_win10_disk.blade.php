{!! $shebang !!}
kernel {{ $winAssetsBase }}/wimboot
initrd --name winpeshl.ini {{ $winAssetsBase }}/winpeshl.ini winpeshl.ini
params
param uuid {{ $uuid }}
param mac {{ $mac }}
param debug {{ $winDebug }}
param version {{ $windowsVersion }}
param action {{ $winAction }}
iseq ${platform} efi && param bios uefi || param bios legacy
initrd --name install.bat {!! $installBatUrl !!}##params install.bat
params
param uuid {{ $uuid }}
param mac {{ $mac }}
param action {{ $winAction }}
param version {{ $windowsVersion }}
param disk {{ $winDisk }}
param perso {{ $winPerso }}
iseq ${platform} efi && param bios uefi || param bios legacy
initrd --name unattend.xml {!! $unattendXmlUrl !!}##params unattend.xml
initrd --name BCD {{ $winVersionBase }}/boot/bcd BCD
initrd --name boot.sdi {{ $winVersionBase }}/boot/boot.sdi boot.sdi
initrd --name boot.wim {{ $winVersionBase }}/sources/boot.wim boot.wim
boot
