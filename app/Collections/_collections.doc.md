# 🎯 Qu'est-ce qu'une Collection ?

Une Collection Laravel est une classe qui encapsule un tableau PHP et lui ajoute des méthodes expressives et chainables pour manipuler les données de façon claire et concise. L'idée est d'encapsuler les fonctions utilisées de manière récurrente dans une classe spécifique pour éviter de recopier la même logique partout.

## Avant:
```php
$parcs = [
    ['name' => 'Salle 101', 'type' => 'room', 'machines' => 20],
    ['name' => 'Bâtiment A', 'type' => 'building', 'machines' => 0],
    ['name' => 'Lab Info', 'type' => 'lab', 'machines' => 15],
];

// Pour récupérer seulement les salles avec plus de 10 machines :
$salles = [];
foreach ($parcs as $parc) {
    if ($parc['type'] === 'room' && $parc['machines'] > 10) {
        $salles[] = $parc;
    }
}
// Puis les trier par nom
usort($salles, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});
```

## Après:
```php
$parcs = collect([
    ['name' => 'Salle 101', 'type' => 'room', 'machines' => 20],
    ['name' => 'Bâtiment A', 'type' => 'building', 'machines' => 0],
    ['name' => 'Lab Info', 'type' => 'lab', 'machines' => 15],
]);

$salles = $parcs
    ->where('type', 'room')
    ->where('machines', '>', 10)
    ->sortBy('name');
```