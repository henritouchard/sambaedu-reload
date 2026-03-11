<?php

namespace Database\Seeders;

use App\Models\Shortcut;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShortcutSeeder extends Seeder
{
    /**
     * Seed la table shortcuts avec des données de test
     */
    public function run(): void
    {
        DB::statement('TRUNCATE shortcuts RESTART IDENTITY CASCADE');
        $this->command->info('Table shortcuts vidée.');

        $shortcuts = [
            [
                'key' => 'firefox',
                'name' => 'Firefox',
                'owner' => 'Eleves,Profs',
                'place' => 'desktop',
                'is_global' => false,
                'windows_link' => 'C:\Program Files\Mozilla Firefox\firefox.exe',
                'windows_icon' => 'C:\Program Files\Mozilla Firefox\firefox.exe,0',
                'linux_link' => '/usr/bin/firefox',
                'linux_startupwmclass' => 'firefox',
            ],
            [
                'key' => 'libreoffice-writer',
                'name' => 'LibreOffice Writer',
                'owner' => 'Eleves,Profs',
                'place' => 'desktop',
                'is_global' => false,
                'windows_link' => 'C:\Program Files\LibreOffice\program\swriter.exe',
                'windows_icon' => 'C:\Program Files\LibreOffice\program\swriter.exe,0',
                'linux_link' => '/usr/bin/libreoffice',
                'linux_args' => '--writer',
                'linux_startupwmclass' => 'libreoffice-writer',
            ],
            [
                'key' => 'pronote',
                'name' => 'Pronote',
                'owner' => 'Eleves,Profs,Administratifs',
                'place' => 'desktop',
                'is_global' => true,
                'windows_link' => 'C:\Program Files\Mozilla Firefox\firefox.exe',
                'windows_args' => 'https://pronote.etablissement.fr',
                'windows_icon' => '%APPDATA%\pronote.ico',
                'linux_link' => '/usr/bin/firefox',
                'linux_args' => 'https://pronote.etablissement.fr',
            ],
            [
                'key' => 'ent',
                'name' => 'ENT Établissement',
                'owner' => '',
                'place' => 'desktop',
                'is_global' => true,
                'windows_link' => 'C:\Program Files\Mozilla Firefox\firefox.exe',
                'windows_args' => 'https://ent.etablissement.fr',
                'linux_link' => '/usr/bin/firefox',
                'linux_args' => 'https://ent.etablissement.fr',
            ],
            [
                'key' => 'scratch',
                'name' => 'Scratch',
                'owner' => 'Eleves',
                'place' => 'desktop',
                'is_global' => false,
                'windows_link' => 'C:\Program Files\Scratch Desktop\Scratch Desktop.exe',
                'linux_link' => '/usr/bin/scratch-desktop',
            ],
            [
                'key' => 'vscode',
                'name' => 'Visual Studio Code',
                'owner' => 'Profs',
                'place' => 'taskbar',
                'is_global' => false,
                'windows_link' => 'C:\Program Files\Microsoft VS Code\Code.exe',
                'linux_link' => '/usr/bin/code',
                'linux_startupwmclass' => 'Code',
            ],
            [
                'key' => 'calculatrice',
                'name' => 'Calculatrice',
                'owner' => 'Eleves',
                'place' => 'startup',
                'is_global' => false,
                'windows_link' => 'calc.exe',
                'linux_link' => '/usr/bin/gnome-calculator',
            ],
        ];

        foreach ($shortcuts as $shortcut) {
            Shortcut::create($shortcut);
        }

        $this->command->info(count($shortcuts) . ' raccourcis créés.');
    }
}
