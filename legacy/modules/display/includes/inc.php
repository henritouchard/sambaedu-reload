<?php

/*
 * Test si le fichier est une image
 */
function check_image_mime($file)
{
    if ($file != '.' && $file != '..') {
        return true;
    }
    return false;
}

/*
 * retourne les sections
 * de tous les flux RSS enregistrés
 *
 */
function returnflux()
{
    $output = "";
    $conf = read_config_display();
    $config = get_config();

    if (! empty($conf["flux"])) {
        foreach ($conf['flux'] as $k => $v) {
            $nom = "$k";
            $url = $v['url'];
            $duration = $v['duration'] * 1000;
            $nb = $v['numbers'];
            /*
             * Using Guzzle to download RSS/XML url
             */
            require_once (dirname(__FILE__) . '/../../vendor/autoload.php');
            $opt = [
                'cookies' => true
            ];
            curl_proxy_options($config, $opt);
            $client = new GuzzleHttp\Client($opt);
            try {
                $response = $client->request('GET', $url, [
                    'connect_timeout' => 2
                ]);
            } catch (\GuzzleHttp\Exception\TransferException $e) {
                continue;
            }
            // ini_set('user_agent', 'SambaEdu');
            // $rss = simplexml_load_file($url);
            $rss = simplexml_load_string($response->getBody());
            if ($rss == "") {
                continue;
            }

            $output .= "<section data-background-image=\"IMG/fond1.png\" data-autoslide=\"3000\">
                            <h1 style=\"color: #ed3434;\">
                            <p>$nom</p>
            				</h1>
				            <h3 style=\"color: #ed3434;\"></h3>
				            <div style=\"color: #ffffff;\">
        					<p>&nbsp;</p>
            				</div>
			                 </section>
			                 ";
            $i = 0;
            /*
             * Les flux WordPress sont pas dans des <channel></channel> bizarrement
             */
            $RCI = $rss->channel->item;
            if (sizeof($RCI) == 0) {
                $RCI = $rss->item;
            }

            foreach ($RCI as $item) {
                $i ++;
                $link = $item->link; // extract the link
                $title = $item->title; // extract the title
                $date = $item->pubDate; // extract the date
                $description = strip_tags($item->description);
                $img = $item->enclosure['url'] ?? "";
                if ($img == "") {
                    $img = $item->children('media', true)->content->attributes()['url'] ?? "";
                }
                $output .= "\n<section  data-background-image=\"IMG/fond2.png\" data-autoslide=\"$duration\">\n
        			<h3 style=\"color:#ff5c5c;\" > $title </h3>\n
        			<div style=\"color:#140209;\">\n
        			<div class=\"layout-container\">\n";
                /*
                 * On tente de trouver une image valable dans link
                 * si on n'en n'a pas
                 */
                if (empty($img) && (! empty($link))) {
                    $img = get_image_url($config, $link);
                }
                /*
                 * On n'affiche pas l'image s'il n'y en a pas
                 */
                if ($img != "") {
                    $output .= "<div class=\"feature-img-container\">\n
        				<img width=\"1024\" height=\"685\" src=\"$img\" class=\"attachment-large size-large wp-post-image\" alt=\"\" srcset=\"\" sizes=\"(max-width: 1024px) 100vw, 1024px\" />\n
        			</div>\n";
                }

                $output .= "<div class=\"content-container\" style=\"color:#ffffff;\">\n
        			<p>$description</p>\n
        			</div>\n
        			</div>\n
        			</div>\n
        			</section>";
                if ($i >= $nb) {
                    break;
                }
            }
        }
    }
    return $output;
}

/*
 * Retourne les sections avec toutes les images
 * dans $DIR
 * Il faut que apache soit configuré pour ça
 */
function returnImages()
{
    $DIR = "/var/sambaedu/Docs/images/";
    $output = "";
    $images = glob("$DIR*.{jpg,jpeg,gif,png,svg,webp}", GLOB_BRACE);
    $conf = read_config_display();
    $duration = 3000;
    if (! empty($conf["images"])) {
        $duration = 1000 * $conf["images"]["duration"];
    }
    foreach ($images as $key => $value) {
        $output .= "\n
        <section data-background-image=\"/images/" . basename($value) . "\" data-autoslide=\"$duration\">
        <div style=\"color:#ffffff;\"><p>&nbsp;</p>
        <p>
        </p>
        </div>
        </section>";
    }
    return $output;
}

/*
 * Recherche d'une image dans une page web donnée en URL
 */
function get_image_url($config, $url)
{
    $xml = new DOMDocument();
    require_once (dirname(__FILE__) . '/../../vendor/autoload.php');
    $opt = [
        'cookies' => true
    ];
    curl_proxy_options($config, $opt);
    $client = new GuzzleHttp\Client($opt);
    try {
        $response = $client->request('GET', "$url", [
            'connect_timeout' => 2
        ]);
    } catch (\GuzzleHttp\Exception\TransferException $e) {
        return "";
    }
    $body = $response->getBody();
    if ($body == "") {
        return "";
    }
    @$xml->loadHTML($body);
    if ($xml == "") {
        return "";
    }
    $xml = $xml->getElementById('main') ?? "";
    $links = array();
    if (empty($xml)) {
        return "";
    }
    foreach ($xml->getElementsByTagName('img') as $link) {
        $links[] = array(
            'url' => $link->getAttribute('src'),
            'text' => $link->nodeValue
        );
    }
    if (! empty($links[0])) {
        return $links[0]['url'];
    } else {
        return "";
    }
}

?>