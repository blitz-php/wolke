<?php

namespace BlitzPHP\Wolke\Commands;

use Ahc\Cli\Output\Color;
use BlitzPHP\Database\Commands\DatabaseCommand;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\ModelInspector;
use Symfony\Component\Finder\Finder;

class Entity extends DatabaseCommand
{
    /**
     * {@inheritDoc}
     */
    protected string $name = 'entity:show';

    /**
     * {@inheritDoc}
     */
    protected string $description = 'Afficher les informations relatives à un modèle Wolke';

    /** 
     * {@inheritDoc}
     */
    protected array $arguments = [
        'model' => 'Le modèle à afficher'
    ];

    /** 
     * {@inheritDoc}
     */
    protected array $options = [
        '--group' => 'Groupe de connexion à utiliser',
        '--json'  => "Afficher l'entité au format JSON",
    ];

    /**
     * Exécution de la commande
     */
    public function handle()
    {
        $modelInspector = service(ModelInspector::class);

        if (null === $model = $this->argument('model')) {
            $model = $this->choice('Quelle entité souhaitez-vous afficher ?', $this->findAvailableEntities());
        }

        $info = $modelInspector->inspect($model, $this->option('group'));
        

        $this->display(
            $info['class'],
            $info['database'],
            $info['table'],
            $info['policy'],
            $info['attributes'],
            $info['relations'],
            $info['events'],
            $info['observers']
        );

        return 0;
    }

    /**
     * Affiche les informations du modèle.
     *
     * @param  class-string<Model>  $class
     * @param  class-string|null  $policy
     */
    protected function display(string $class, string $database, string $table, ?string $policy, Collection $attributes, Collection $relations, Collection $events, Collection $observers): void
    {
        $this->option('json')
            ? $this->displayJson($class, $database, $table, $policy, $attributes, $relations, $events, $observers)
            : $this->displayCli($class, $database, $table, $policy, $attributes, $relations, $events, $observers);
    }

    /**
     * Affiche les informations du modèle au format JSON.
     *
     * @param  class-string<Model>  $class
     * @param  class-string|null  $policy
     */
    protected function displayJson(string $class, string $database, string $table, ?string $policy, Collection $attributes, Collection $relations, Collection $events, Collection $observers): void
    {
        $this->json((
            new Collection([
                'class'      => $class,
                'database'   => $database,
                'table'      => $table,
                'policy'     => $policy,
                'attributes' => $attributes,
                'relations'  => $relations,
                'events'     => $events,
                'observers'  => $observers,
            ]))->toArray()
        );
    }

    /**
     * Affiche les informations du modèle pour l'interface en ligne de commande.
     *
     * @param  class-string<Model>  $class
     * @param  class-string|null  $policy
     */
    protected function displayCli(string $class, string $database, string $table, ?string $policy, Collection $attributes, Collection $relations, Collection $events, Collection $observers): void
    {
        $this->newLine();

        $this->justify($class, options: ['first' => ['fg' => Color::GREEN, 'bold' => 1]]);
        $this->justify('Base de données', $database);
        $this->justify('Table', $table);
        
        if ($policy) {
            $this->justify('Policy', $policy);
        }

        $this->newLine();

        $this->justify(
            $this->color->ok('Attributs', ['bold' => 1]), 
            $this->color->comment('Type') . '/' . $this->color->warn('Cast')
        );

        foreach ($attributes as $attribute) {
            $first = trim(sprintf(
                '%s %s',
                $attribute['name'],
                (new Collection(['increments', 'unique', 'nullable', 'fillable', 'hidden', 'appended']))
                    ->filter(fn ($property) => $attribute[$property])
                    ->map(fn ($property) => $this->color->comment($property))
                    ->implode($this->color->comment(','))
            ));

            $second = (new Collection([
                $attribute['type'],
                $attribute['cast'] ? $this->color->warn($attribute['cast'], ['bold' => 1]) : null,
            ]))->filter()->implode($this->color->comment(' / '));

            $this->justify($first, $second);

            if ($attribute['default'] !== null) {
                $this->bulletList(
                    [sprintf('default: %s', $attribute['default'])],
                );
            }
        }

        $this->newLine();

        $this->justify(
            $this->color->ok('Relations', ['bold' => 1])
        );

        foreach ($relations as $relation) {
            $this->justify(
            sprintf('%s %s', 
                $relation['name'], 
                $this->color->comment($relation['type'])
                ),
            $relation['related']
            );
        }

        $this->newLine();

        $this->justify(
            $this->color->ok('Événements', ['bold' => 1])
        );

        foreach ($events as $event) {
            $this->justify(
                sprintf('%s', $event['event']),
                sprintf('%s', $event['class']),
            );
        }

        $this->newLine();

        $this->justify(
            $this->color->ok('Observateurs', ['bold' => 1])
        );

        foreach ($observers as $observer) {
            $this->justify(
                sprintf('%s', $observer['event']),
                implode(', ', $observer['observer'])
            );
        }

        $this->newLine();
    }

    /**
     * Liste des noms d'entités possibles.
     *
     * @return list<string>
     */
    protected function findAvailableEntities(): array
    {
        $path      = app_path('Entities');
        $modelPath = is_dir($path) ? $path : dirname($path);

        return (new Collection(Finder::create()->files()->depth(0)->in($modelPath)))
            ->map(fn ($file) => $file->getBasename('.php'))
            ->sort()
            ->values()
            ->all();
    }
}
