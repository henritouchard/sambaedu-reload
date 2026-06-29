        ┌─────────────────────────────────────────────┐
        │  EPIC 28  — Socle (modèle, persistance,      │
        │  ingestion, précédence amont>local)          │  ← GATE DUR, solo
        └───────┬───────────────┬───────────────┬──────┘
                │               │               │
        ┌───────▼──────┐  ┌─────▼──────┐  ┌─────▼───────────┐
        │  EPIC 29     │  │  EPIC 32   │  │  EPIC 33        │
        │  Enforcement │  │  Release / │  │  Schéma versionné│
        │  (verrou/    │  │  rupture   │  │  (cross-team    │
        │  permissif)  │  │  du lien   │  │  controlHub)    │
        └──┬────────┬──┘  └────────────┘  └─────────────────┘
           │        │
   Story 29.1│       │(tout 29)
   (wpkg.* fix)│      │
        ┌─────▼──┐ ┌─▼────────┐
        │EPIC 31 │ │ EPIC 30  │
        │install │ │ labels / │
        │bornée  │ │ ciblage  │
        └────────┘ └──────────┘