{!! $shebang !!}
kernel Win10/wimboot
initrd --name winpeshl.ini Win10/winpeshl.ini winpeshl.ini
params
param uuid {{ $uuid }}
param mac {{ $mac }}
param debug {{ $debug }}
param version {{ $version }}
param action {{ $action }}
iseq ${platform} efi && param bios uefi || param bios legacy
initrd --name install.bat Win10/repair.bat.php##params install.bat
params
param uuid {{ $uuid }}
param mac {{ $mac }}
param action {{ $action }}
param version {{ $version }}
param disk {{ $disk }}
param perso {{ $perso }}
iseq ${platform} efi && param bios uefi || param bios legacy
initrd --name diskpart.txt Win10/diskpart.php##params diskpart.txt
initrd --name BCD {{ $version }}/boot/bcd BCD
initrd --name boot.sdi {{ $version }}/boot/boot.sdi boot.sdi
initrd --name boot.wim {{ $version }}/sources/boot.wim boot.wim
boot
