<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb;

use RuntimeException;
use Throwable;

/**
 * Story 57.1 — Le rendu : du PHP nu, dans un tampon de sortie.
 *
 * Pas de moteur de gabarit, pas de compilation, pas de cache à invalider : le
 * périmètre est de quatre pages. Ce qui compte est la discipline —
 * `bbb_e()` sur toute valeur, `bbb_url()` sur toute URL.
 *
 * `page()` enveloppe une vue dans le layout autonome de l'extension. Une
 * extension n'a PAS la barre de navigation de SE5 (doctrine du témoin SSO) :
 * elle est un site à part, relié par un lien de retour explicite (FR16).
 */
final class View
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $template, array $data = []): string
    {
        $path = $this->directory . '/' . $template . '.php';

        if (! is_file($path)) {
            throw new RuntimeException('Vue introuvable : ' . $template);
        }

        $level = ob_get_level();
        ob_start();

        try {
            (static function (string $__path, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__path;
            })($path, $data);
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Rend une vue DANS le layout.
     *
     * @param  array<string, mixed>  $data
     */
    public function page(string $template, array $data, string $title, Env $env, ?Identity $identity = null): string
    {
        return $this->render('layout', [
            'title' => $title,
            'content' => $this->render($template, $data + ['identity' => $identity, 'env' => $env]),
            'identity' => $identity,
            'env' => $env,
        ]);
    }
}
