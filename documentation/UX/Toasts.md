
## Étape 5 : Utiliser le trait WithToasts pour les messages


### Utilisation dans un composant

```php
use App\Components\Traits\WithToasts;

new class extends Component {
    use WithToasts;

    public function delete(string $id)
    {
        if (Gate::denies('delete-shortcut')) {
            $this->toastAccessDenied();
            return;
        }

        // ...
        $this->toastSuccess('Élément supprimé');
    }
};
```