{{-- Story 3.7 - port iso-legacy `sambaedu/ipxe/actions/clonezilla_live.php`.
     Le legacy n'a PAS de bloc `params` iPXE (handshake fait amont par le
     menu clonezilla - cf. `IpxeMenuRenderer::renderClonezillaMenu()`). Les
     doubles espaces ci-dessous (`nomodeset  ocs_prerun`,
     `keyboard-layouts="fr"  locales`) sont preserves iso-legacy (post-review
     #3 - 2026-05-22). Le firmware iPXE tolere le whitespace, mais on garde la
     parite textuelle stricte pour faciliter les diffs legacy/SE5. --}}
{!! $shebang !!}
kernel {{ $osUrl }}/clonezilla/vmlinuz
initrd --name initram.img {{ $osUrl }}/clonezilla/initrd.img
imgargs vmlinuz initrd=initram.img boot=live config noswap nolocales edd=on nomodeset  ocs_prerun=""  ocs_live_run="" ocs_live_extra_param="" keyboard-layouts="fr"  locales="fr_FR.UTF-8" vga=788 nosplash noprompt fetch={{ $osUrl }}/clonezilla/filesystem.squashfs
boot
