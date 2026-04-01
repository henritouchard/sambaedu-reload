<?php
$ipxe = "#!ipxe\n";
$ipxe .= "chain http://" . $_SERVER['SERVER_NAME'] . ":909/boot.php?mac=\${netX/mac}&uuid=\${uuid}";
ipxe_out($config, $ipxe);
?>