{!! $shebang !!}
echo
echo ============================================================
echo  Acces refuse - {{ $reasonLabel }}
echo ============================================================
echo
@if($reasonHint)
echo {{ $reasonHint }}
@endif
echo
echo Retour au menu de boot dans 8 secondes...
sleep 8
chain --replace --autofree {{ $serverBaseUrl }}/ipxe/boot##params
 || exit 0
