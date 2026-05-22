{{-- Story 3.7 - port iso-legacy `sambaedu/ipxe/hdt.php`.
     Post-review #12 (2026-05-22) - les chemins pxelinux sont injectes par
     `IpxeActionResolver::resolveToolsVariables()` (pattern Epic 3), plus
     d'appel `config()` direct dans le Blade. --}}
{!! $shebang !!}
set 209:string {{ $serverBaseUrl }}{{ $hdtPxelinuxCfg }}
chain --replace --autofree {{ $serverBaseUrl }}{{ $hdtPxelinux0Path }}
