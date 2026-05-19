{!! $shebang !!}
kernel {{ $osUrl }}/clonezilla/vmlinuz initrd=initram.igz boot=live config noswap nolocales edd=on nomodeset ocs_prerun="mount -t auto /dev/sda2 /home/partimag/" ocs_live_run="ocs-sr -e1 auto -e2 -r -j2 -p reboot restoreparts savesda1 sda1" ocs_live_extra_param="" keyboard-layouts="fr" ocs_live_batch="no" locales="fr_FR.UTF-8" vga=788 nosplash noprompt fetch={{ $osUrl }}/clonezilla/filesystem.squashfs
initrd --name initram.igz {{ $osUrl }}/clonezilla/initrd.img
boot
