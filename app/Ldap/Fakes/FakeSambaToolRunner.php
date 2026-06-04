<?php

declare(strict_types=1);

namespace App\Ldap\Fakes;

use App\Gpo\Support\GpoActionLog;
use App\Gpo\Support\SambaToolRunner;
use Illuminate\Contracts\Process\ProcessResult;

/**
 * `samba-tool` runner FAKE e2e (Story 21.2, T1/T2 — canal C).
 *
 * Bindé sur {@see SambaToolRunner} UNIQUEMENT en `e2e` (cf. `AppServiceProvider`).
 * N'invoque JAMAIS le binaire `samba-tool` : aucune écriture n'atteint
 * `samba-ad-dc` (AC1). Chaque commande d'ÉCRITURE est capturée dans le journal
 * via {@see FakeAdRecorder} ; un résultat synthétique de succès (exit 0) est
 * retourné pour que les parcours aboutissent (AC2).
 *
 * Lectures (`list`, `show`, `listall`) : non journalisées (ce sont des reads),
 * retour exit 0 + stdout vide. Conséquence VOULUE : `AdUserManager::exists()` /
 * `AdMachineManager::check()` voient « absent » → enchaînent le `create`, qui
 * est alors capturé. Le parcours create aboutit ; rien de réel n'est touché.
 *
 * Hérite de {@see SambaToolRunner} (et non d'une interface) car le repo
 * auto-wire le type concret partout (`AdUserManager`, `AdMachineManager`,
 * `GpoService`…). Le binding du container substitue cette sous-classe.
 */
class FakeSambaToolRunner extends SambaToolRunner
{
    public function __construct(
        private readonly FakeAdRecorder $recorder,
    ) {
    }

    /**
     * Sous-commandes de LECTURE : ne déclenchent aucune capture d'écriture.
     */
    private const READ_SUBCOMMANDS = ['list', 'show', 'listall', 'listcontainers'];

    public function run(array $args, ?GpoActionLog $log = null): ProcessResult
    {
        $object = $args[0] ?? '';
        $action = $args[1] ?? '';

        // Lectures : retour vide cohérent, aucune écriture journalisée.
        if (in_array($action, self::READ_SUBCOMMANDS, true)) {
            return $this->syntheticSuccess('');
        }

        // Écriture : journaliser puis retourner un succès synthétique.
        $target = $this->extractTarget($object, $action, $args);
        $this->recorder->record(
            actionType: $this->actionType($object, $action),
            target: $target,
            payload: ['args' => $this->redactSecretArgs($object, $action, $args)],
            channel: FakeAdRecorder::CHANNEL_SAMBATOOL,
        );

        return $this->syntheticSuccess('[fake-samba-tool] ' . $object . ' ' . $action . ' ' . (string) $target);
    }

    /**
     * Type d'action normalisé pour le journal (ex. `ad.user.create`,
     * `ad.computer.create`, `ad.group.addmembers`).
     */
    private function actionType(string $object, string $action): string
    {
        $object = $object !== '' ? $object : 'unknown';
        $action = $action !== '' ? $action : 'unknown';

        return 'ad.' . $object . '.' . $action;
    }

    /**
     * Masque les arguments contenant un secret avant journalisation (le mot de
     * passe ne doit JAMAIS être persisté, même en e2e — parité doctrine
     * `AdUserManager`/`SambaToolRunner`).
     *
     * Cas couverts :
     *  - `user create <login> <password> …` → 4e arg positionnel masqué ;
     *  - `--newpassword=<pwd>` / `--password=<pwd>` (option inline) masqués.
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    private function redactSecretArgs(string $object, string $action, array $args): array
    {
        // `user create <login> <password>` : args[0]=user, [1]=create, [2]=login, [3]=password.
        if ($object === 'user' && $action === 'create' && isset($args[3]) && ! str_starts_with((string) $args[3], '--')) {
            $args[3] = '***redacted***';
        }

        foreach ($args as $i => $arg) {
            if (preg_match('/^--(new)?password=/i', (string) $arg) === 1) {
                $args[$i] = preg_replace('/=.*/', '=***redacted***', (string) $arg);
            }
        }

        return $args;
    }

    /**
     * Cible logique de la commande : 1er argument positionnel après la
     * sous-commande qui ne soit pas une option (`--…`).
     *
     * @param  list<string>  $args
     */
    private function extractTarget(string $object, string $action, array $args): ?string
    {
        for ($i = 2; $i < count($args); $i++) {
            $arg = (string) $args[$i];
            if ($arg !== '' && ! str_starts_with($arg, '--')) {
                return $arg;
            }
        }

        return null;
    }

    private function syntheticSuccess(string $output): ProcessResult
    {
        // Délègue au helper protégé hérité de SambaToolRunner (dans `app/Gpo`, où
        // la facade `Process` est autorisée). Évite d'importer `Process` sous
        // `app/Ldap/*` (interdit par LdapNamespaceTest) ET de dépendre de la
        // signature interne de FakeProcessResult.
        return $this->syntheticResult($output);
    }
}
