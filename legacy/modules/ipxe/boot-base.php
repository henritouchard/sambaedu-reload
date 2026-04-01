<?php
$ipxe = "#!ipxe\n";
$menu_timeout = '5000';
require_once ('actions/params.php');

function title($name)
{
    // the max number of characters for resolution 1024 x 768 is 107
    $total_length = 107;
    $name_length = strlen($name);
    $start = intval(($total_length - $name_length) / 2);
    $end = $total_length - $start - $name_length;
    $title = str_repeat("-", $start) . $name . str_repeat("-", $end);
    return "item --gap -- {$title}\n";
}

// set resolution and background
$ipxe .= "console --x 1024 --y 768 --picture png/ipxe-se4.png\n";
$ipxe .= ":menu\n";
$ipxe .= "menu Preboot eXecution Environment for ${ip}\n";
$ipxe .= "set menu-default exit\n";
$ipxe .= "set menu-timeout $menu_timeout\n";

$ipxe .= title("Menu");
$ipxe .= "item --key x exit (x) boot le poste normalement par le disque dur\n";
$ipxe .= "item --key l leger  (l) Utiliser le poste en client leger \n";
$ipxe .= "item  login  menu administrateur\n";
$ipxe .= "choose --default \${menu-default} --timeout \${menu-timeout} selected && goto \${selected} || exit 0\n";

$ipxe .= ":exit\n";
$ipxe .= "echo Booting harddisk ...\n";
$ipxe .= "sanboot --no-describe --drive 0x80\n";

$ipxe .= ":login\n";
$ipxe .= "chain --replace php/login.check.php\n";

$ipxe .= ":leger\n";
$ipxe .= "chain --replace php/client-leger/client-leger.php\n";
ipxe_out($config, $ipxe);
?>