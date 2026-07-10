<?php

/**
 * Fonctions de gestion des partages

 * @Projet SambaEdu

 * @Auteurs Denis Bonnenfant @ Equipe Sambaedu

 * @Note: Ce fichier de fonction doit etre appele par un include

 * @Licence Distribue sous la licence GPL
 */
function check_acls(string $path, array $acls)
{
    if (! is_dir($path)) {
        system("sudo mkdir -p " . $path);
    }
    $disk_acls = array();
    exec("sudo getfacl -c '" . $path . "' 2>/dev/null", $disk_acls);
    sort($acls);
    array_pop($disk_acls);
    sort($disk_acls);
    return ($acls == $disk_acls);
}

function set_acls(string $path, array $acls, bool $recurse = true)
{
    $res = true;
    if ($recurse)
        $option = "-R";
    else
        $option = "";

    //$list_acls = implode(",", $acls);

    system("sudo setfacl " . $option . " -b '" . $path . "' >/dev/null 2>&1", $ret);
    foreach ($acls as $acl) {
        system("sudo setfacl " . $option . " -m \"" . $acl . "\" '" . $path . "' >/dev/null 2>&1", $ret);
        $res &= ($ret == 0);
    }
    return $res;
}

/**
 * Création d'un dossier avec acls
 *
 * @description permet la création d'un dossier plus mise en place des acls fournies via array
 * @Parametres $path le chemin à creer, $owner le propriétaire et $acls le tableau avec les acls
 * @returns true si ok
 *
 */
function create_dir_acls(string $path, string $owner, array $acls)
{
    if (! is_dir($path)) {
        system("sudo mkdir -p " . $path);
        system("sudo chown " . $owner . ":domain\\ admins '" . $path . "'  2>&1");
        // system("sudo chgrp -v \"domain admins\" " . $path );

        set_acls($path, $acls);
    }
}

/**
 * Suppression d'une acl sur un dossier
 *
 * @description permet la Suppression d'une acl sur un dossier donné
 * @Parametres $path le chemin concerné, $acl la chaine, true si recursif
 * @returns true si ok
 *
 */
function remove_acl(string $path, string $acl, bool $recurse = true)
{
    if ($recurse)
        $option = "-R";
    else
        $option = "";

    system("sudo setfacl " . $option . " -x \"" . $acl . "\" '" . $path . "' >/dev/null 2>&1 &", $ret);

    if ($ret == 0)
        return true;
    else
        return false;
}

function get_facl($path)
{
    exec("sudo getfacl -c -E '" . $path . "'", $out, $res);
    if ($res == 0) {
        $acl = [];
        foreach ($out as $line) {
            $t = explode(":", $line);
            if (strlen($line) > 1 && count($t)) {
                if ($t[0] == "default") {
                    if (empty($t[2])) {
                        $acl[$t[1]]['mode'] = $t[3];
                    } else {
                        $t[2] = preg_replace("/\\\\040/", " ", $t[2]);
                        $acl[$t[2]]['type'] = $t[1];
                        $acl[$t[2]]['default_mode'] = $t[3];
                    }
                } else {
                    if (empty($t[1])) {
                        $acl[$t[0]]['mode'] = $t[2];
                    } else {
                        $t[1] = preg_replace("/\\\\040/", " ", $t[1]);
                        $acl[$t[1]]['type'] = $t[0];
                        $acl[$t[1]]['mode'] = $t[2];
                    }
                }
            }
        }
        return $acl;
    } else {
        return false;
    }
}

/**
 * Ajout d'une acl sur un dossier donné
 *
 * @description permet l'ajout d'une acl sur un dossier
 * @Parametres $path le chemin concerné, $acl la chaine, true si recursif
 * @returns true si ok
 *
 */
function add_acl(string $path, string $acl, bool $recurse = true)
{
    if ($recurse)
        $option = "-R";
    else
        $option = "";

    // $cmd="sudo setfacl " . $option . " -m \"" . $acl . "\" " . $path . " >/dev/null 2>&1 &";
    // var_dump($cmd);
    system("sudo setfacl " . $option . " -m \"" . $acl . "\" '" . $path . "' >/dev/null 2>&1 &", $ret);
    if ($ret == 0)
        return true;
    else
        return false;
}

function clean_homes($config, $delete = false)
{
    $message = "<br>";
    $trash = $del = 0;
    if (! is_dir("/home/trash"))
        system("sudo mkdir -p /home/trash");
    // on deplace les homes des utilisateurs supprimés
    $trasheds = search_ad($config, "*", "user", $config['dn']['trash']);
    $trasheds = array_column($trasheds, 'cn');
    foreach ($trasheds as $trashed) {
        if (is_dir("/home/" . $trashed)) {
            $message .= $trashed . "<br>\n";
            system("sudo mv '/home/" . $trashed . "' '/home/trash/" . $trashed . "'");
            $trash++;
        }
    }
    // on déplace les homes des inconnus
    $homes = scandir("/home");
    $users = search_ad($config, "*", "user");
    $users = array_column($users, 'cn');
    $specials = array(
        ".",
        "..",
        "profiles",
        "trash"
    );
    $unknowns = array_diff($homes, $users, $specials, $trasheds);
    foreach ($unknowns as $unknown) {
        $message .= $unknown . "<br>\n";
        system("sudo mv '/home/" . $unknown . "' '/home/trash/" . $unknown . "'");
        $del++;
    }
    if ($delete) {
        batch_command("rm -fr /home/trash/*");
        $message .= "Suppression des homes de " . $trash . " utilisateurs de la corbeille et " . $del . " inconnus<br>\n";
    } else {
        $message .= "Déplacement des homes dans /home/trash de " . $trash . " utilisateurs de la corbeille et " . $del . " inconnus<br>\n";
    }
    // batch_write();
    return $message;
}

function clean_profiles($config, $nom = "*")
{
    $message = "<br>";
    $del = 0;
    $valids = array();
    if (! is_dir("/home/profiles"))
        system("sudo mkdir -p /home/profiles");
    $profiles = scandir("/home/profiles");
    $users = search_ad($config, $nom, "user", $config['dn']['people']);
    $users = array_column($users, 'cn');
    $specials = array(
        ".",
        ".."
    );
    foreach ($profiles as $profile) {
        foreach ($users as $user) {
            if (preg_match("/$user(\.V[0-9]).?/", $profile))
                $valids[] = $profile;
        }
    }
    if ($nom == "*") {
        $unknowns = array_diff($profiles, $valids, $specials);
    } else {
        $unknowns = $valids;
    }
    foreach ($unknowns as $unknown) {
        $message .= $unknown . "<br>\n";
        batch_command("rm -fr '/home/profiles/" . $unknown . "'");
        $del++;
    }
    if ($nom == "*") {
        $message .= "Suppression de " . $del . " profiles<br>\n";
    } else {
        $message .= "Suppression du profile pour " . $nom . "<br>\n";
    }
    batch_write("fast");
    return $message;
}

function repair_profiles($config, $nom = "*")
{
    $message = "";
    $del = 0;
    $valids = array();
    if (! is_dir("/home/profiles"))
        system("sudo mkdir -p /home/profiles");
    $profiles = scandir("/home/profiles");
    $users = search_ad($config, $nom, "user");
    $users = array_column($users, 'cn');
    $specials = array(
        ".",
        ".."
    );
    foreach ($profiles as $profile) {
        foreach ($users as $user) {
            if (preg_match("/$user(\.V[0-9]).?/", $profile))
                $valids[] = $profile;
        }
    }
    if ($nom == "*") {
        $profiles = array_diff($profiles, $valids, $specials);
    } else {
        $profiles = $valids;
    }
    foreach ($profiles as $profile) {
        $out = [];
        if (is_file("/home/profiles/" . $profile . "/NTUSER.DAT")) {
            exec("getfacl '/home/profiles/" . $profile . "/NTUSER.DAT'", $out);
            foreach ($out as $line) {
                $m = [];
                if (preg_match("/group:([0-9]){6}:/", $line, $m))
                    $uid = $m[1];
                /*
                 * batch_command("setfacl -x \"group:" . $uid . ":rwx \"" . $profile . "/NTUSER.DAT");
                 * batch_command("setfacl -m \"group:" . $user . ":rwx \"" . $profile . "/NTUSER.DAT");
                 * batch_command("setfacl -x \"group:" . $uid . ":rwx \"" . $profile . "/ntuser.ini");
                 * batch_command("setfacl -m \"group:" . $user . ":rwx \"" . $profile . "/ntuser.ini");
                 * batch_command("setfacl -x \"group:" . $uid . ":rwx \"" . $profile . "/ntuser.pol");
                 * batch_command("setfacl -m \"group:" . $user . ":rwx \"" . $profile . "/ntuser.pol");
                 */
                $message .= $profile . "<br>\n";
                batch_command("rm -fr '/home/profiles/" . $profile . "'");
            }

            $del++;
        }
    }
    $message .= "correction de " . $del . " profiles pour " . $nom . "<br>\n";
    batch_write();
    return $message;
}

/**
 *
 * file: partages.inc.php
 *
 * @Repertoire: includes/
 */
function invert_login($config, $login, &$html = NULL)
{
    if (! empty($login) && is_array($login)) {
        $nom = explode(".", $login['cn']);
    } else {
        $nom = explode(".", $login);
    }
    if (count($nom) == 2) {
        // on inverse
        $eleve = $nom[1] . "." . $nom[0];
        if (! is_array($login)) {
            $res = search_user($config, $login, $config['dn']['people']);
        }
        if (! empty($res)) {
            // c'est un login
            $out = 0;
            $res = array();
            $login1 = preg_replace("/ /", "\\\\ ", $login);
            @exec("sudo -s ls -d1 /var/sambaedu/Classes/Classe_*/" . $login1 . " 2>/dev/null", $res, $out);
            if ($out == 0) {
                // $REP = explode(" ", $res);
                if (count($res) > 0) {
                    foreach ($res as $tmpClasse) {
                        $tmpClasse = preg_replace("!^/var/sambaedu/Classes/Classe_(.+)/$login$!", "\${1}", $tmpClasse);
                        $html .= "Inversion de $login -> $eleve<br>\n";
                        system("sudo /bin/mv '/var/sambaedu/Classes/Classe_$tmpClasse/$login' '/var/sambaedu/Classes/Classe_$tmpClasse/$eleve'");
                        $html .= "classe : $tmpClasse\n";
                        $html .= "inversion de /var/sambaedu/Classes/Classe_" . $tmpClasse . "/" . $eleve . " avec " . $eleve . " faite<br>\n";
                    }
                }
            }
        }
        return $eleve;
    } else {
        if (is_array($login)) {
            return $login['cn'];
        } else {
            return $login;
        }
    }
}

function cree_rep(array $config, $login, $OldClasse = "")
{
    // fait les repertoires
    // Recherche de l'Eleve dans les Classes
    $html = "";
    $Classe = "";
    $cnClasse = "";
    if (! empty($login)) {
        if (is_array($login)) {
            $login_name = $login['cn'];
        } else {
            $login_name = $login;
        }
        $eleve = invert_login($config, $login_name, $html);
        $res1 = list_classes($config, $login);
        if (count($res1) == 1) {
            $Classe = $res1[0];
            $cnClasse = "Classe_" . $Classe;
            $ClasseAcl = strtolower(preg_replace("/ /", "\\\\040", $Classe));
            if (! empty($OldClasse)) {
                if ("Classe_" . $OldClasse != $cnClasse) {
                    $html .= "  Changement de classe de '$eleve' : Classe_$OldClasse -> $cnClasse.<br>\n";
                    if (! is_dir("/var/sambaedu/Classes/$cnClasse/$eleve")) {
                        system("sudo /bin/mkdir  '/var/sambaedu/Classes/$cnClasse/$eleve'");
                    }
                    if (! is_dir("/var/sambaedu/Classes/$cnClasse/$eleve/Archives")) {
                        system("sudo /bin/mv -f '/var/sambaedu/Classes/Classe_$OldClasse/$eleve' '/var/sambaedu/Classes/$cnClasse/$eleve/Archives'");
                    } else {
                        system("sudo /bin/rm -fr '/var/sambaedu/Classes/Classe_$OldClasse/$eleve'");
                    }
                }
            }
            if (! is_dir("/var/sambaedu/Classes/$cnClasse/$eleve")) {
                $inverse = invert_login($config, $eleve);
                if (is_dir("/var/sambaedu/Classes/$cnClasse/.$eleve")) {
                    $html .= "Restauration du dossier '$cnClasse/.$eleve'.<br>\n";
                    system("sudo /bin/mv '/var/sambaedu/Classes/$cnClasse/.$eleve' '/var/sambaedu/Classes/$cnClasse/$eleve'");
                } elseif (is_dir("/var/sambaedu/Classes/$cnClasse/." . $inverse)) {
                    $html .= "Restauration du dossier '$cnClasse/.$inverse'.<br>\n";
                    system("sudo /bin/mv '/var/sambaedu/Classes/$cnClasse/.$inverse' '/var/sambaedu/Classes/$cnClasse/$eleve'");
                } else {
                    $html .= "Création du dossier '$cnClasse/$eleve'.<br>\n";
                    system("sudo /bin/mkdir '/var/sambaedu/Classes/$cnClasse/$eleve'");
                }
            } // else {
            $html .= "Mise en place des droits pour le dossier $eleve.<br>\n";
            $command = "/usr/bin/setfacl -R -P --set \"user::rwx,group::---,user:$login_name:rwx,group:equipe_" . $ClasseAcl . ":rwx,group:domain\\040admins:rwx,mask::rwx,other::---,default:user::rwx,default:group::---,default:group:equipe_" . $ClasseAcl . ":rwx,default:group:domain\\040admins:rwx,default:mask::rwx,default:other::---,default:user:$login_name:rwx\" '/var/sambaedu/Classes/$cnClasse/$eleve'";
            batch_command($command);
            // Modifie le groupe par defaut
            system("sudo chgrp domain\ admins '/var/sambaedu/Classes/$cnClasse/$eleve'");
            system("sudo chown www-admin '/var/sambaedu/Classes/$cnClasse/$eleve'");
            // }
        } elseif (count($res1) > 1) {
            $html .= "<div class='error_msg'>Erreur : '$eleve' est inscrit dans plusieurs Classes !</div><br>\n";
        } else {
            // L'eleve n'est inscrit dans aucune classe
            if ($OldClasse != "") {
                $html .= "$eleve n'est inscrit dans aucune classe : Renommage de 'Classe_$OldClasse/$eleve' en 'Classe_$OldClasse/.$eleve'.<br>\n";
                system("sudo /bin/mv '/var/sambaedu/Classes/Classe_$OldClasse/$eleve' '/var/sambaedu/Classes/Classe_$OldClasse/.$eleve'");
            } else {
                $html .= "<div class='error_msg'>Erreur : '$login_name' ne correspond pas à un eleve !</div><br>\n";
            }
        }
    } else {
        $html .= "login vide ?<br>";
    }
    return $html;
}

function update_eleve($config, $login, &$html = NULL)
{
    if (! empty($login)) {
        $eleve = invert_login($config, $login, $html);
        // Recherche du dossier Eleve
        $out = 0;
        $res = array();
        $eleve1 = preg_replace("/ /", "\\\\ ", $eleve);
        @exec("sudo ls -d1 /var/sambaedu/Classes/Classe_*/" . $eleve1 . " 2>/dev/null", $res, $out);
        if ($out == 0) {
            // $reps = explode(" ", $res);
            if (count($res) > 0) {
                foreach ($res as $rep) {
                    if (preg_match("/Classe_grp_/", $rep)) {
                        $html .= "Ancien groupe '$rep' ignoré.<br>\n";
                    } else {
                        if ($rep != "") {
                            if (preg_match("!^/var/sambaedu/Classes/Classe_.+/$eleve$!", $rep)) {
                                $rep = preg_replace("!^/var/sambaedu/Classes/Classe_(.+)/$eleve$!", "\${1}", $rep);
                                $html .= $rep . " : ";
                            } else {
                                $html .= "Bizarre : Le répertoire '$rep' de l'ancienne classe de '$eleve' n'est pas de la forme '/var/sambaedu/Classes/Classe_*/$eleve' ! <br>\n";
                                $rep = ""; // On laisse tomber la gestion de l'ancien répertoire
                            }
                        }
                        $html .= cree_rep($config, $login, $rep);
                    }
                }
            }
        } else {

            $html .= cree_rep($config, $login);
        }
        return true;
    } else {
        return false;
    }
}

function temps(&$time)
{
    $ret = (microtime(true) - $time);
    $ret .= "<br>";
    $time = microtime(true);
    return $ret;
}

/**
 * met à jour le dossier classe
 *
 * @param array $config
 * @param string $classes
 * @param string $echange
 *            : par défaut false on ne change pas l'état, true on active
 * @param bool $etat
 *            : active ou desactive le dossier echange ( mais ne le supprime pas )
 */
function update_classes(array $config, string $nom = "*", bool $echange = false, bool $etat = true)
{
    $html = "";
    $classes = list_classes($config, $nom);
    if (count($classes) > 0) {
        // Au moins une classe a ete trouvee
        foreach ($classes as $Classe) {
            $cnClasse = "Classe_" . $Classe;
            $ClasseAcl = strtolower(preg_replace("/ /", "\\\\040", $Classe));
            $cnClasseAcl = strtolower("Classe_" . $ClasseAcl);
            $html .= "<b>Mise à jour du partage de la classe : $Classe</b><br>\n";

            if (! is_dir("/var/sambaedu/Classes/$cnClasse")) {
                if (is_dir("/var/sambaedu/Classes/.$cnClasse")) {
                    $html .= "<b> restauration du repertoire de la classe $Classe</b><br>\n";
                    system("sudo /bin/mv '/var/sambaedu/Classes/.$cnClasse' '/var/sambaedu/Classes/$cnClasse'");
                } else {
                    $html .= "<b> Création du repertoire de la classe $Classe</b><br>\n";
                    system("sudo /bin/mkdir '/var/sambaedu/Classes/$cnClasse'");
                }
            }
            if (is_dir("/var/sambaedu/Classes/$cnClasse")) {

                if ($echange) {
                    // test dossier echange
                    if (is_dir("/var/sambaedu/Classes/$cnClasse/_echange")) {
                        $acl = get_facl("/var/sambaedu/Classes/" . $cnClasse . "/_echange")[strtolower($cnClasse)]['mode'];
                        if ($etat && $acl != "rwx") {
                            $html .= "<b> Activation du répertoire échange de la classe $Classe</b><br>\n";
                            $acl = "rwx";
                        } elseif (! $etat && $acl != "---") {
                            $html .= "<b> Désactivation du répertoire échange de la classe $Classe</b><br>\n";
                            $acl = "---";
                        }
                    } else {
                        $html .= "<b> Création du répertoire échange de la classe $Classe</b><br>\n";
                        system("sudo /bin/mkdir '/var/sambaedu/Classes/$cnClasse/_echange'");
                        $acl = "rwx";
                    }
                    if (! empty($acl)) {
                        batch_command("/usr/bin/setfacl -R -P -m \"group:$cnClasseAcl:$acl,default:group:$cnClasseAcl:$acl\" '/var/sambaedu/Classes/$cnClasse/_echange'");
                    }
                } else {
                    // Modifie le groupe par defaut
                    system("sudo chgrp domain\ admins '/var/sambaedu/Classes/$cnClasse'");
                    system("sudo chown www-admin '/var/sambaedu/Classes/$cnClasse'");
                    $command = "setfacl -R -P --set \"user::rwx,group::---,group:equipe_$ClasseAcl:rwx,group:domain\\040admins:rwx,mask::rwx,other::---,default:user::rwx,default:group::---,default:group:equipe_$ClasseAcl:rwx,default:group:domain\\040admins:rwx,default:mask::rwx,default:other::---\" '/var/sambaedu/Classes/$cnClasse'";
                    batch_command($command);
                    $html .= "traitement de  $cnClasse/_travail<br>\n";
                    if (! is_dir("/var/sambaedu/Classes/$cnClasse/_travail")) {
                        system("sudo /bin/mkdir '/var/sambaedu/Classes/$cnClasse/_travail'");
                    }
                    if (is_dir("/var/sambaedu/Classes/$cnClasse/_travail")) {

                        batch_command("/usr/bin/setfacl -R -P -m \"group:$cnClasseAcl:rx,default:group:$cnClasseAcl:rx\" '/var/sambaedu/Classes/$cnClasse/_travail'");

                        // Modifie le groupe par defaut
                        system("sudo chgrp domain\ admins '/var/sambaedu/Classes/$cnClasse/_travail'");
                        system("sudo chown www-admin '/var/sambaedu/Classes/$cnClasse/_travail'");
                    }
                    $html .= "traitement de   $cnClasse/_profs<br>\n";
                    if (! is_dir("/var/sambaedu/Classes/$cnClasse/_profs")) {
                        system("sudo /bin/mkdir '/var/sambaedu/Classes/$cnClasse/_profs'");
                    }
                    // Modifie le groupe par defaut
                    system("sudo chgrp domain\ admins '/var/sambaedu/Classes/$cnClasse/_profs'");
                    system("sudo chown www-admin '/var/sambaedu/Classes/$cnClasse/_profs'");
                    // premiere passe : on analyse les repertoires
                    $out = 0;
                    $reps_eleves = array();
                    $reps_eleves = scandir("/var/sambaedu/Classes/" . $cnClasse);
                    $exclusion = [
                        ".",
                        "..",
                        "_travail",
                        "_profs",
                        "_echange"
                    ];
                    foreach ($reps_eleves as $oldeleve) {
                        if (! in_array($oldeleve, $exclusion) && ! preg_match("/^\..*$/", $oldeleve)) {
                            // On met à jour les anciens eleves de la classe
                            // $oldeleve = preg_replace("!^/var/sambaedu/Classes/$cnClasse/!", "", $oldeleve);
                            $login = invert_login($config, $oldeleve, $html);
                            if (! update_eleve($config, search_user($config, $login, $config['dn']['people']), $html)) {
                                $html .= "$login n'existe pas, on renomme le dossier en .$oldeleve<br>\n";
                                system("sudo /bin/mv '/var/sambaedu/Classes/$cnClasse/$oldeleve' '/var/sambaedu/Classes/$cnClasse/.$oldeleve'");
                            }
                            // Modifie le groupe par defaut
                            if (is_dir("/var/sambaedu/Classes/$cnClasse/$login")) {
                                system("sudo chgrp domain\ admins '/var/sambaedu/Classes/$cnClasse/$login'");
                                system("sudo chown www-admin '/var/sambaedu/Classes/$cnClasse/$login'");
                            }
                        }
                    }

                    // deuxieme passe : on cherche dans l'annuaire
                    $members = list_eleves($config, $Classe);
                    foreach ($members as $member) {
                        $login = search_user($config, ldap_dn2cn($member), $config['dn']['people']);
                        if (! empty($login)) { # cas de l'utilisateur dans OU=Trash (qui n'est pas dans OU=utilisateurs)
                            // D.B. On met met a jour les eleves actuels de la classe pas encore faits
                            $eleve = invert_login($config, $login);
                            if (! is_dir("/var/sambaedu/Classes/$cnClasse/$eleve")) {
                                update_eleve($config, $login, $html);

                                // Modifie le groupe par defaut
                                if (is_dir("/var/sambaedu/Classes/$cnClasse/$eleve")) {
                                    system("sudo chgrp domain\ admins '/var/sambaedu/Classes/$cnClasse/$eleve'");
                                    system("sudo chown www-admin '/var/sambaedu/Classes/$cnClasse/$eleve'");
                                }
                            }
                        }
                    }
                }
                // Retrait du droit w à Equipe_$CLASSE et ajout de rx au groupe $cnClasse (Classe_ ) sur le dossier /var/sambaedu/Classes/$cnClasse
                batch_command("/usr/bin/setfacl -m \"group:equipe_$ClasseAcl:rx,group:$cnClasseAcl:rx\" '/var/sambaedu/Classes/$cnClasse'");
            }
        }
    } elseif (is_dir("/var/sambaedu/Classes/Classe_$nom")) {
        $Classe = $nom;
        if (preg_match("/grp_/", $Classe)) {
            $html .= "Ancien groupe '$Classe' ignorée. utilisez le menu groupe<br>\n";
        } else {
            // le répertoire existe, mais la classe non : on renomme en .Classe_truc, au cas ou
            $html .= "Le groupe n'existe plus : Renommage de la classe $Classe. en .Classe_$Classe<br>\n";
            system("sudo /bin/mv '/var/sambaedu/Classes/Classe_$Classe' '/var/sambaedu/Classes/.Classe_$Classe'");
        }
    }
    return $html;
}

/**
 * liste les devoirs d'un prof
 * Les devoirs sont dans Classe_$classe/_travail/devoirs
 * si un dossier ou un fichier appartient au prof il est considéré comme un SUJET (devoir à récuperer)
 * si un dossier existe et contient un dossier ou fichier nommé "sujet" on considère le devoir comme récupéré
 *
 * La recherche des devoirs est faite à intervalles réguliers. Le résultat est mis en cache pour permettre la récupération rapide.
 *
 * @param unknown $config
 * @param unknown $user
 * @return string
 */
function liste_devoirs($config, $user = "")
{
    $liste = [];
    foreach (list_classes($config, $user) as $classe) {
        $directory = "/var/sambaedu/Classes/Classe_" . $classe . "/_travail/devoirs/";
        $devoirs = scandir($directory);
        foreach ($devoirs as $devoir) {
            if ((empty(($user) || posix_getpwuid(fileowner($devoir)) == $user)) && ($devoir != "." || $devoir != "..")) {
                $liste[$devoir]['user'] == posix_getpwuid(fileowner($devoir));
                $liste[$devoir]['classe'] == $classe;
                if (is_dir($devoir)) {
                    $travaux = scandir($devoir);
                    $sujet = true;
                    foreach ($travaux as $travail) {
                        if (preg_match("/sujet/", $travail)) {
                            $sujet = false;
                            $liste[$devoir]['etat'] = "récupéré";
                            $liste[$devoir]['nombre'] = count($travaux) - 3;
                        } elseif ($travail != "." || $travail != "..") {
                            if (is_link($travail)) {
                                $liste[$devoir]['etat'] = "en cours";
                            }
                            $liste[$devoir]['travaux'][] = $travail;
                        }
                    }
                    if ($sujet) {
                        unset($liste[$devoir]);
                        $liste[$devoir]['etat'] = "à récupérer";
                    }
                } else {
                    $liste[$devoir]['etat'] = "à récupérer";
                }
            }
        }
    }
    return $liste;
}

function find_devoirs($config, $user)
{
    $devoirs = liste_devoirs($config, $user);
    foreach ($devoirs as $nom => $devoir) {
        switch ($devoir['etat']) {
            case "à récupérer":
                $command .= "sudo mkdir -p \"/var/sambaedu/Classes/Classe_" . $devoir['classe'] . "/_travail/devoirs/$nom\"";
                $command .= "sudo chown -R " . $devoir[$user] . " \"/var/sambaedu/Classes/Classe_" . $devoir['classe'] . "/_travail/devoirs/$nom\"";
                foreach (list_eleves($config, $devoir['classe']) as $eleve) {
                    $command .= "sudo find \"/var/sambaedu/Classes/Classe_" . $devoir['classe'] . "/$eleve\" -name \"$nom\" -exec ln -s {} /var/sambaedu/Classes/Classe_" . $devoir['classe'] . "/_travail/devoirs/devoir/$eleve\"";
                    $command .= "sudo find \"/home/$user\" -name \"$nom\" -exec ln -s {} /var/sambaedu/Classes/Classe_" . $devoir['classe'] . "/_travail/devoirs/devoir/$eleve\"";
                }
                break;
            case "en cours":
                // on efface le lien, on mv le fichier puis on fait un lien à la place chez l'eleve, et on le passe en ro.
                break;
        }
    }
}

if (!function_exists('roaming_profiles_stats')) {
function roaming_profiles_stats()
{
    $res = [];
    if (! file_exists("/tmp/du.txt")) {
        return $res;
    }
    $stats = file("/tmp/du.txt", FILE_IGNORE_NEW_LINES);
    foreach ($stats as $line) {
        $val = explode("\t", $line);
        $dir = explode("/", $val[1], 2);
        $res[$dir[1]]['sum'] = @$res[$dir[1]]['sum'] + $val[0];
        $res[$dir[1]]['user'][$dir[0]] = round($val[0] / 1024 / 1024, 1);
        @$res[$dir[1]]['nb']++;
    }
    foreach ($res as $dir => $val) {
        $res[$dir]['average'] = round($val['sum'] / $val['nb'] / 1024 / 1024, 1);
        $res[$dir]['sum'] = round($val['sum'] / 1024 / 1024, 0);
    }
    array_multisort($res, SORT_NUMERIC, SORT_DESC, array_column($res, 'average'), SORT_NUMERIC, SORT_DESC);
    return $res;
}
}
