<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Commands\Generators;

use BlitzPHP\Cli\Commands\Generators\GeneratorCommand;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Utilities\String\Text;

/**
 * Génère un fichier squelette d'entité.
 */
class Entity extends GeneratorCommand
{
    protected string $name = 'make:entity';

    protected string $description = 'Génère un nouveau fichier d\'entité.';

    protected string $service = 'Service de génération de code';

    protected array $arguments = [
        'name' => 'Le nom de la classe d\'entité.',
    ];

    protected array $options = [
        '-a, --all'        => ['Générer des classes de migration, de seeder, de contrôleur de ressources et de validateur de requête pour l\'entité'],
        '-c, --controller' => ["Créer un nouveau contrôleur pour l'entité"],
        '-m, --migration'  => ["Créer un nouveau fichier de migration pour l'entité"],
        '--morph-pivot'    => ["Indique si l'entité générée doit être une entité de table intermédiaire polymorphe personnalisée"],
        '-s, --seed'       => ["Créer un nouveau générateur de données pour l'entité"],
        '-p, --pivot'      => ["Indique si l'entité générée doit être une entité de table intermédiaire personnalisée"],
        '-r, --resource'   => 'Indique si le contrôleur généré doit être un contrôleur de ressources',
        '--api'            => ['Indique si le contrôleur généré doit être un contrôleur de ressource API'],
        '-v, --validation' => ['Créer de nouvelles classes de validation et les utiliser dans le contrôleur de ressources'],
        '-n, --namespace'  => ["Définit l'espace de noms racine", APP_NAMESPACE],
        '--suffix'         => ['Ajouter le titre du composant au nom de la classe (par exemple, User => UserEntity).'],
        '--force'          => ["Créer la classe même si l'entité existe déjà"],
    ];

	protected string $component     = 'Entity';
	protected string $directory     = 'Entities';
	protected string $template      = 'entity.tpl.php';
	protected string $templatePath  = __DIR__ . '/Views';
	protected string $classNameLang = 'CLI.generator.className.entity';

    /**
     * {@inheritDoc}
     */
    public function handle()
    {
        if ($this->option('all')) {
            $this->mergeOptions([
                'seed'       => true,
                'migration'  => true,
                'controller' => true,
                'resource'   => true,
            ]);
        } else if ($this->missingAllOptions('seed', 'validation', 'migration', 'resource')) {
            $choices = $this->choices("Souhaitez-vous l'un des éléments suivants ?", [
                'seed'       => 'Générateur de données pour la base de données',
                'validation' => 'Validation des requêtes',
                'migration'  => 'Migration',
                'resource'   => 'Contrôleur de ressources',
            ], optional: true);

            (new Collection($choices))->each(fn ($option) => $this->mergeOptions([$option => true]));
        }

        if ($this->option('migration')) {
            $this->createMigration();
        }

        if ($this->option('seed')) {
            $this->createSeeder();
        }

        if ($this->option('controller') || $this->option('resource') || $this->option('api')) {
            $this->createController();
        } elseif ($this->option('validation')) {
            $this->createRequestValidation();
        }

        $this->generateClass($this->parameters());
    }

    /**
     * {@inheritDoc}
     */
    protected function prepare(string $class): string
    {
        if ($this->option('pivot')) {
            $data = ['{extends}' => 'Pivot', '{use}' => 'BlitzPHP\Wolke\Relations\Pivot'];
        } else if ($this->option('morph-pivot')) {
            $data = ['{extends}' => 'MorphPivot', '{use}' => 'BlitzPHP\Wolke\Relations\MorphPivot'];
        } else {
            $data = ['{extends}' => 'Entity', '{use}' => 'BlitzPHP\Wolke\Entity'];
        }

        return $this->parseTemplate($class, array_keys($data), array_values($data));
    }

    /**
     * Créer un fichier de migration pour l'entité.
     */
    protected function createMigration(): void
    {
        $table = Text::snake(Text::pluralStudly(Helpers::classBasename($this->argument('name'))));

        if ($this->option('pivot')) {
            $table = Text::singular($table);
        }

        $this->call('make:migration', [
            'name'     => "create_{$table}_table",
            '--create' => $table,
        ]);
    }

    /**
     * Créer un fichier de seeder pour l'entité.
     */
    protected function createSeeder(): void
    {
        $seeder = $this->entityName();

        $this->call('make:seeder', ['name' => "{$seeder}Seeder"]);
    }

    /**
     * Créer un contrôleur pour l'entité.
     */
    protected function createController(): void
    {
        $entity = $this->entityName();

        $this->call(
            command  : 'make:controller',
            arguments: ['name' => "{$entity}Controller"],
            options  : array_filter([
                '--entity'     => $this->option('resource') || $this->option('api') ? $entity : null,
                '--restful'    => $this->option('api'),
                '--validation' => $this->option('validation') || $this->option('all'),
            ])
        );
    }

    /**
     * Créer un validateur de requete pour l'entité.
     */
    protected function createRequestValidation()
    {
        $validation = $this->entityName();

        $this->call('make:validation', ['name' => "Create{$validation}Validation"]);

        $this->call('make:validation', ['name' => "Update{$validation}Validation"]);
    }

    protected function entityName(): string
    {
        return Text::studly(Helpers::classBasename($this->argument('name')));
    }
}
