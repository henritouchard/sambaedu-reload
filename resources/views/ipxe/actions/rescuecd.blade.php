{!! $shebang !!}
kernel {{ $osUrl }}/sysresccd/boot/x86_64/vmlinuz initrd=initram.igz ip=dhcp copytoram nofirewall archisobasedir=sysresccd archiso_http_srv={{ $osUrl }}/ checksum rootpass={{ $se4installPasswd }} setkmap=fr ar_source={{ $autorunUrl }} ar_attempts=5 ar_suffixes=no ar_nodel
initrd --name intel_ucode.img {{ $osUrl }}/sysresccd/boot/intel_ucode.img
initrd --name amd_ucode.img {{ $osUrl }}/sysresccd/boot/amd_ucode.img
initrd --name initram.igz {{ $osUrl }}/sysresccd/boot/x86_64/sysresccd.img
boot
