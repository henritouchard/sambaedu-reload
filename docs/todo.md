# Suppression de tout le code mort VM à tester
● Voici les conclusions de la cartographie T10 (inspection SSH read-only du dev) transformées en commandes prêtes à taper. Tu les exécutes toi-même sur la VM (le worktree ne doit pas muter la VM).

  ⚠️ Avant tout — 2 points de séquencement

  1. Idéalement, applique ceci APRÈS avoir mergé le code 27-14 sur main. Tant que la VM tourne le main actuel (sans les suppressions), le code legacy existe encore côté serveur. Le kill-switch étant OFF
  (410/no-op), le risque est faible même avant merge, mais l'ordre propre = merge → neutralisation.
  2. Le bootstrap se4_agent_bootstrap ne doit JAMAIS être touché. Le dev affirme qu'il vit dans le repo (resources/gpo/), pas sous /usr/share/sambaedu/gpo/. La commande de pré-check ci-dessous le confirme
  avant le mv — ne saute pas cette étape.

  0. Connexion

  ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50

  1. Pré-checks (lecture seule — sûrs à tout moment)

  # Existence + contenu des cibles
  ls -la /usr/share/sambaedu/gpo/ /usr/share/sambaedu/gpo/sambaedu-gpo/
  ls -la /etc/sambaedu/applications/

  # SAFETY : le bootstrap NE DOIT PAS être ici. Doit retourner VIDE :
  find /usr/share/sambaedu/gpo -iname '*agent*bootstrap*'
  👉 Si la dernière commande retourne quelque chose, STOP : ne lance pas le mv du dossier entier — dis-le-moi, je te donnerai la variante qui déplace chaque template sauf le bootstrap.

  2. Neutralisation réversible (Bucket A)

  # 2a. Templates GPO de config legacy (publiés par l'ex-canal GpoPublisher/WpkgGpoSynchronizer, supprimés en 27.14)
  mv -v /usr/share/sambaedu/gpo/sambaedu-gpo   /usr/share/sambaedu/gpo/sambaedu-gpo.disabled-27.14
  mv -v /usr/share/sambaedu/gpo/etab_Bureau.zip /usr/share/sambaedu/gpo/etab_Bureau.zip.disabled-27.14

  # 2b. Surcharges de config d'app legacy (consommées par ApplicationScriptsAssembler/AppPolicyController supprimés)
  mv -v /etc/sambaedu/applications/firefox     /etc/sambaedu/applications/firefox.disabled-27.14
  mv -v /etc/sambaedu/applications/thunderbird /etc/sambaedu/applications/thunderbird.disabled-27.14
  mv -v /etc/sambaedu/applications/veyon       /etc/sambaedu/applications/veyon.disabled-27.14
  mv -v /etc/sambaedu/applications/gpos.json   /etc/sambaedu/applications/gpos.json.disabled-27.14
  Le renommage en .disabled-27.14 est invisible pour le code SE5 : GpoTemplateRegistry ne scanne que …/sambaedu-gpo/ (pas sambaedu-gpo.disabled-27.14) et ne reconnaît que les .zip (pas
  .zip.disabled-27.14).

  3. Vérif post-neutralisation

  # Le bootstrap doit rester publiable — depuis /var/www/sambaedu-reload :
  cd /var/www/sambaedu-reload && php artisan tinker --execute="echo app(\App\Gpo\Support\GpoTemplateRegistry::class)->isPublishable('se4_agent_bootstrap') ? 'OK bootstrap' : 'KO';"
  # (si le code 27.14 n'est pas encore mergé, ce check vaut surtout après merge)

  4. Rollback (si besoin — c'est le but du « réversible »)

  cd /usr/share/sambaedu/gpo && mv -v sambaedu-gpo.disabled-27.14 sambaedu-gpo; mv -v etab_Bureau.zip.disabled-27.14 etab_Bureau.zip
  cd /etc/sambaedu/applications && for x in firefox thunderbird veyon gpos.json; do mv -v "$x.disabled-27.14" "$x"; done

  ⛔ À NE PAS toucher (Bucket B/C/D — encore utilisés ou à purger plus tard)

  - /etc/sambaedu/applications/shortcuts/ → Bucket B : encore lu par ShortcutsService/ImageManagerService + route shortcuts.icon (la bascule 27-7 content-addressed n'est pas à 100 %). (Note :
  ShortcutCompilerService qui le lisait aussi a été supprimé en review, mais les 2 autres consommateurs restent → on garde.)
  - /etc/sambaedu/applications/{winget,wallpaper}/ → D (winget_out hors-scope conservé ; wallpaper = bibliothèque).
  - /etc/sambaedu/{sambaedu.conf,reservations.inc,www-sambaedu.keytab,hashes,*.pem} → D (runtime SE5).
  - /var/sambaedu/unattended/install/ (assets iPXE OS, partage WPKG, wine) → D (référencé par config/sambaedu.php + config/ipxe.php).
  - Paquets sambaedu-client-* + dépôt APT se4.list → B (purge après vérif qu'aucun poste neuf/migré n'en dépend).

  Après ~1–2 semaines d'observation

  Si zéro régression (logs Apache legacy 8082, conformité agent), tu peux passer à la suppression sèche : trash (ou rm) des *.disabled-27.14. Pas avant.

  ---Distinct de ceci : les fantômes inotify (fichiers de code supprimés — legacy/modules/gpo/*.php, app/Gpo/Services/*, app/Auth/V1/Migration/*) qui resteront sous /var/www/sambaedu-reload/ côté VM après
  merge — c'est un autre nettoyage (code, pas config serveur). Dis-moi si tu veux la liste de commandes pour celui-là aussi (au moment du merge).

