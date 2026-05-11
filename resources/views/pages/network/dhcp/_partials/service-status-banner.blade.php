{{--
    Story 8.1 — Bannière statut service DHCP (AC1, AC6).

    Variables attendues (héritées du composant parent) :
      - $serviceStatus : ['active' => bool, 'details' => string]
--}}

@if ($serviceStatus['active'])
    <div class="alert alert-success">
        <i class="fa-solid fa-circle-check text-lg"></i>
        <div>
            <h3 class="font-bold">Service DHCP actif</h3>
            <p class="text-sm">isc-dhcp-server.service répond.</p>
        </div>
    </div>
@else
    <div class="alert alert-error">
        <i class="fa-solid fa-triangle-exclamation text-lg"></i>
        <div>
            <h3 class="font-bold">Service DHCP injoignable</h3>
            <p class="text-sm">
                Le service DHCP n'est pas actif sur la VM ({{ $serviceStatus['details'] }}).
                Les mutations (création/édition/suppression) seront enregistrées en base mais
                ne seront pas appliquées tant que le service ne sera pas relancé.
            </p>
        </div>
    </div>
@endif
