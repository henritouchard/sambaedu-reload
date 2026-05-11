# /etc/sambaedu/reservations.inc — fixture Story 8.1 (T8b / AC9).
# Reproduit le format export legacy (`ldap.inc.php:4947 export_dhcp_reservations`).
# Cas couverts :
#   - blocs nominaux
#   - commentaires #
#   - commentaires //
#   - ligne vide
#   - espaces / tabs variables dans le bloc
#   - MAC dupliquée (deuxième bloc doit être skipped)
#   - bloc malformé (accolade manquante → erreur, on continue)
#   - bloc avec MAC invalide → erreur

host poste01 { hardware ethernet 00:11:22:33:44:55; fixed-address 10.0.0.10; }
host poste02 { hardware ethernet aa:bb:cc:dd:ee:01; fixed-address 10.0.0.11; }

// Commentaire alternatif
host poste03 {
    hardware ethernet  aa:bb:cc:dd:ee:02 ;
    fixed-address  10.0.0.12 ;
}

# Bloc avec MAC dupliquée : doit être skippé
host poste99 { hardware ethernet 00:11:22:33:44:55; fixed-address 10.0.0.99; }

# Bloc malformé (pas d'accolade fermante) — doit être ignoré
host posteBroken { hardware ethernet 00:11:22:33:44:00; fixed-address 10.0.0.50

# Bloc avec MAC invalide (trop courte, mais matche [0-9a-fA-F:]+) → erreur
host posteBadMac { hardware ethernet aa:bb:cc; fixed-address 10.0.0.51; }
