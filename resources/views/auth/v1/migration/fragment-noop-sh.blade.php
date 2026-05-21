{{-- Story 16.13bis : shebang prefixée par MigrationFragmentRenderer
     (PHP strip le `#!` initial d'un fichier compilé Blade). --}}
# ===================================================================
# SambaEdu - Fragment migration SE4 -> SE5 (Story 16.13bis)
# No-op : poste deja migre (lookup serveur WorkstationMigrationStatus).
# ===================================================================
echo "{!! $noop_message !!}"
exit 0
