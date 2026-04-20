@php
/**
 * Export PDF — cartouches multi-users post-bulk-reset (story 2.6).
 *
 * Tri : établissement → classe → nom → prénom (fait en amont côté service).
 * Saut de page sur changement d'établissement puis de classe.
 *
 * Fonts dyslexie-friendly disponibles dans resources/fonts/ :
 *   OpenDyslexic-Regular, OpenDyslexic-Bold, mononoki-Regular, mononoki-Bold,
 *   LexicaUltralegible-Regular. Embarquées via @font-face si html2pdf supporte.
 *
 * Contraintes :
 *   - les mots de passe clairs apparaissent ici (destination légitime de la
 *     donnée), le document doit être détruit après distribution (mention RGPD).
 */

$groupedByEtabClass = [];
foreach ($results as $row) {
    $meta = $row['metadata'] ?? [];
    $etab = (string) ($meta['structure'] ?? 'Non renseigné');
    $classes = $meta['classes'] ?? [];
    $classe = is_array($classes) ? ($classes[0] ?? 'Non renseigné') : (string) $classes;
    if ($classe === '') {
        $classe = 'Non renseigné';
    }
    $groupedByEtabClass[$etab][$classe][] = $row;
}
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Réinitialisation des mots de passe — {{ $generatedAt->format('d/m/Y H:i') }}</title>
    <style>
        page { size: A4 portrait; margin: 10mm; }
        body {
            font-family: "LexicaUltralegible", "DejaVu Sans", sans-serif;
            font-size: 10pt;
            color: #1a1a1a;
        }
        .header {
            border-bottom: 2px solid #333;
            padding-bottom: 4mm;
            margin-bottom: 4mm;
        }
        .header h1 {
            margin: 0;
            font-size: 14pt;
            font-weight: bold;
        }
        .header .meta {
            font-size: 8pt;
            color: #555;
            margin-top: 1mm;
        }
        .rgpd-warning {
            background: #fff3cd;
            border-left: 4px solid #e0a800;
            padding: 2mm 3mm;
            font-size: 8pt;
            margin-bottom: 3mm;
        }
        .section-etab {
            font-size: 12pt;
            font-weight: bold;
            background: #e9ecef;
            padding: 2mm;
            margin-bottom: 2mm;
        }
        .section-classe {
            font-size: 10pt;
            font-weight: bold;
            color: #495057;
            margin: 2mm 0 2mm 0;
        }
        .cartouche {
            border: 1px solid #333;
            padding: 2mm 3mm;
            margin-bottom: 2mm;
            width: 88mm;
            display: inline-block;
            vertical-align: top;
            page-break-inside: avoid;
        }
        .cartouche .nom { font-weight: bold; font-size: 10pt; }
        .cartouche .etab-class { font-size: 8pt; color: #555; }
        .cartouche .ids { font-family: "mononoki", monospace; font-size: 9pt; margin-top: 1mm; }
        .cartouche .mdp {
            font-family: "OpenDyslexic", "mononoki", monospace;
            font-weight: bold;
            font-size: 11pt;
            color: #c0392b;
            padding: 1mm;
            background: #fdf2f2;
            border: 1px dashed #c0392b;
            margin-top: 1mm;
            display: inline-block;
        }
        .cartouche .footer-rgpd {
            font-size: 6pt;
            color: #777;
            margin-top: 1mm;
            font-style: italic;
        }
        .pair {
            width: 100%;
        }
        .pair td {
            vertical-align: top;
            padding: 0;
            width: 50%;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Réinitialisation des mots de passe</h1>
        <div class="meta">
            Opérateur : <strong>{{ $operatorLogin }}</strong>
            &nbsp;·&nbsp; Généré le : <strong>{{ $generatedAt->format('d/m/Y à H:i') }}</strong>
            &nbsp;·&nbsp; Nombre d'utilisateurs : <strong>{{ count($results) }}</strong>
            &nbsp;·&nbsp; Changement forcé : <strong>{{ $forceChange ? 'oui' : 'non (mdp définitif)' }}</strong>
        </div>
    </div>

    <div class="rgpd-warning">
        <strong>RGPD — Document confidentiel :</strong>
        à distribuer individuellement en main propre.
        <strong>Détruire ce document après distribution.</strong>
        Les identifiants présents permettent l'accès aux comptes utilisateurs —
        ne jamais l'envoyer par email ni le laisser sans surveillance.
    </div>

    @php $isFirstEtab = true; @endphp
    @foreach ($groupedByEtabClass as $etab => $byClasse)
        @if (!$isFirstEtab)
            <pagebreak />
        @endif
        @php $isFirstEtab = false; @endphp

        <div class="section-etab">{{ $etab }}</div>

        @php $isFirstClasse = true; @endphp
        @foreach ($byClasse as $classe => $users)
            @if (!$isFirstClasse)
                <pagebreak />
            @endif
            @php $isFirstClasse = false; @endphp

            <div class="section-classe">Classe / Groupe : {{ $classe }}</div>

            <table class="pair" cellspacing="0" cellpadding="0">
                @foreach (array_chunk($users, 2) as $pair)
                    <tr>
                        @foreach ($pair as $row)
                            @php $meta = $row['metadata'] ?? []; @endphp
                            <td>
                                <div class="cartouche">
                                    <div class="nom">
                                        {{ $meta['firstname'] ?? '' }}
                                        {{ $meta['lastname'] ?? '' }}
                                    </div>
                                    <div class="etab-class">
                                        {{ $meta['structure'] ?? '' }}
                                        @if (!empty($classe) && $classe !== 'Non renseigné')
                                            &nbsp;·&nbsp; {{ $classe }}
                                        @endif
                                    </div>
                                    <div class="ids">
                                        Identifiant : <strong>{{ $row['login'] }}</strong>
                                    </div>
                                    <div class="mdp">
                                        {{ $row['new_password'] }}
                                    </div>
                                    <div class="footer-rgpd">
                                        {{ $forceChange ? 'À changer à la première connexion.' : 'Mot de passe définitif.' }}
                                        Document à détruire après remise.
                                    </div>
                                </div>
                            </td>
                        @endforeach
                        @if (count($pair) < 2)
                            <td>&nbsp;</td>
                        @endif
                    </tr>
                @endforeach
            </table>
        @endforeach
    @endforeach
</body>
</html>
