<?php
ini_set('display_errors', 0);
require_once ("includes/inc.php");
require_once "config.inc.php";
$config = get_config();
require_once "display.inc.php";
$etab_name = $config['etab_name'] ?? "";
/*
 * Si l'affichage dynamique est paramétré via url d'un diaporama la page se4fs/display redirige vers le diaporama public
 * Si l'affichage dynamique est paramétré à partir de flux d'informations, la page se4fs/display affiche les informations à intervalle de temps paramétré.
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width" />
<title>Affichage Dynamique</title>
<meta name='robots' content='noindex,follow' />
<link rel='stylesheet' id='wpds_clock-style-css' href='css/clock.css'
    type='text/css' media='all' />
<link rel='stylesheet' id='wpds-style-css' href='css/style.css'
    type='text/css' media='all' />
<link rel='stylesheet' id='reveal.js_css-css' href='css/reveal.css'
    type='text/css' media='all' />
<link rel='stylesheet' id='reveal.js_theme-css' href='css/simple.css'
    type='text/css' media='all' />
<script type='text/javascript' src='/javascript/jquery/jquery.js'></script>
<script type='text/javascript' src='js/custom.modernizr.js'></script>
<?php
/*
 * Affichage d'un diaporama public si configuré
 */
$conf = read_config_display();
if (isset($conf['diapo']) && isset($conf['diapo']['nb'])) {
    $nb_display = $conf['diapo']['nb'];
    for ($i = 1; $i <= $nb_display; $i ++) {
        if (! empty($conf['diapo']['url' . $i])) {
            if ($conf['diapo']['ip' . $i] == $_SERVER['REMOTE_ADDR']) {
                $url = str_replace('&amp;', '&', $conf['diapo']['url' . $i]);
                header('Location:' . $url);
                exit();
            }
        }
    }
}
echo '</head>
<body class="archive tax-channel term-actus term-2 logged-in">
<div class="reveal">
<div class="slides">';
/*
 * IMAGES en plein écran issus du répertoire IMG/IMG/
 * pointant via un lien symbolique vers un /var/sambaedu/Docs/Display
 * éventuellemernt uniquement accessible en partage
 * par ceux qui ont le bon droit ?
 */
$cacheDuration = 300;
$fluxImages = apcu_fetch('fluxImages');
if ($fluxImages === false) {
    $fluxImages = returnImages();
    apcu_store('fluxImages', $fluxImages, $cacheDuration);
}
/*
 * On retourne les flux
 */

$flux = apcu_fetch('flux');
if ($flux === false) {
    $flux = returnflux();
    apcu_store('flux', $flux, $cacheDuration);
}
echo $fluxImages;
echo $flux;
?>
    </div>
</div>
<footer class="footer"
    style="color: #ffffff; background-color: rgba(79, 75, 75, 0.43)">
    <div class="dock">
        <div class="dock-container">
            <div id="wpds-clock-widget-2"
                class="dock-element widget_wpds-clock-widget"
                style="width: 100%">
                <div class="clock">
                    <div class="clock-time">
                        <span class="clock-hours"> </span> <span
                            class="clock-point clock-point-animated">:</span>
                        <span class="clock-minutes"> </span>
                    </div>
                    <div class="clock-date"></div>
                </div>
            </div>
        </div>
    </div>
</footer>

<script>
			var defaultReloadTimeout = 600000;
			var defaultContentChangeCheckInterval = 100000;
			var postModified='4e7287270038680c0c5541cb63d89275';
			var layoutWidth=1200; //960;
			var layoutMargin=0.04;
			var autoPlaySpeed=15000; // <!-- Vitesse par défaut -->
			var transitionStyle='fade';  // slide , fade, zoom, concave convex
			var transitionSpeed='slow';
			var showSlideNumber=false;
			var centerVertically=false;
			var autoplayStoppable=false;
		</script>
<script type='text/javascript' src='js/moment-with-locales.min.js'></script>
<script type='text/javascript' src='js/moment-timezone-with-data.min.js'></script>
<script type='text/javascript' src='js/clock.js'></script>
<script type='text/javascript' src='js/reveal.js'></script>
<script type='text/javascript' src='js/app.js'></script>
<script type='text/javascript' src='js/wp-embed.min.js'></script>


</body>
</html>