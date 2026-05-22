{{-- Story 3.7 - port iso-legacy `sambaedu/ipxe/gparted.php`.
     Post-review #12 (2026-05-22) - les chemins kernel/initrd/filesystem sont
     injectes par `IpxeActionResolver::resolveToolsVariables()` (pattern Epic 3),
     plus d'appel `config()` direct dans le Blade. --}}
{!! $shebang !!}
kernel {{ $serverBaseUrl }}{{ $gpartedKernelPath }} boot=live config union=aufs noswap noprompt vga=791 locales=en_US.UTF-8 keyboard-layouts=NONE gl_batch fetch={{ $serverBaseUrl }}{{ $gpartedFilesystemPath }}
initrd {{ $serverBaseUrl }}{{ $gpartedInitrdPath }}
boot
