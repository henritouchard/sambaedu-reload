{!! $shebang !!}
params
param mac ${net0/mac}
param uuid ${uuid}
param product ${product}
chain --replace --autofree boot##params
 || sleep 10
