# Fixtures WPKG XML attendues — Story 15.2

Ces fichiers servent de référence parité **structurelle** (pas byte-à-byte) pour
les tests feature `HostsXmlControllerTest` / `ProfilesXmlControllerTest`.

## Origine

Le legacy `sambaedu/wpkg/{hosts_xml_out.php, profiles_xml_out.php}` n'a pas pu
être interrogé directement via `curl` lors du dev de la story (LDAP HS sur la
VM dev → `Echec de l'Authentification AD : Can't contact LDAP server`). Les
fixtures ont donc été générées **par dump du contrôleur Reload** + revue
manuelle vs lecture directe du source legacy
(`/var/www/sambaedu/sambaedu/wpkg/hosts_xml_out.php` lignes 1-32 et
`profiles_xml_out.php` lignes 1-37).

Méthode acceptable documentée par la story (cf. `_bmad-output/implementation-artifacts/15-2-generators-xml-ini-par-poste.md` § Testing Standards).

## Notes

- `hosts-PCEXEMPLE.xml` : output minimal du legacy `hosts_xml_out.php` —
  toujours identique, pas de dépendance BDD.
- `profiles-PCEXEMPLE-empty.xml` : output legacy `profiles_xml_out.php` quand
  `info_poste_applications` retourne un tableau vide (poste inconnu en BDD ou
  sans application assignée — parité AC1.3).

Lors du retrait du shim 1bis-11 (Story 15.7), il faudra **rerun** les fixtures
contre le legacy figé (snapshot binaire) pour valider la parité réelle.
