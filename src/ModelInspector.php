<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke;

use BlitzPHP\Container\Container;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileObject;
use stdClass;

class ModelInspector
{
    /**
     * Les méthodes qui peuvent être appelées dans un modèle pour indiquer une relation.
     *
     * @var list<string>
     */
    protected array $relationMethods = [
        'hasMany',
        'hasManyThrough',
        'hasOneThrough',
        'belongsToMany',
        'hasOne',
        'belongsTo',
        'morphOne',
        'morphTo',
        'morphMany',
        'morphToMany',
        'morphedByMany',
    ];

    /**
     * Crée une nouvelle instance de l'inspecteur de modèle.
     */
    public function __construct(protected Container $container)
    {
    }

    /**
     * Extrait les détails du modèle pour le modèle donné.
     *
     * @param  class-string<Model>|string  $model
     * @param  string|null  $connection
     * 
     * @return array{
     *  "class": class-string<Model>, 
     *  database: string, 
     *  table: string, 
     *  policy: class-string|null, 
     *  attributes: Collection, 
     *  relations: Collection, 
     *  events: Collection, 
     *  observers: Collection, 
     *  collection: class-string<\BlitzPHP\Wolke\Collection<Model>>, 
     *  builder: class-string<Builder<Model>>, 
     *  "resource": class-string|null
     * }
     */
    public function inspect(string $model, ?string $connection = null): array
    {
        $class = $this->qualifyModel($model);

        /** @var Model $model */
        $model = $this->container->make($class);

        if ($connection !== null) {
            $model->setConnection($connection);
        }

        return [
            'class'      => get_class($model),
            'database'   => $model->getConnection()->getName(),
            'table'      => $model->getConnection()->prefixTable($model->getTable()),
            'policy'     => $this->getPolicy($model),
            'attributes' => $this->getAttributes($model),
            'relations'  => $this->getRelations($model),
            'events'     => $this->getEvents($model),
            'observers'  => $this->getObservers($model),
            'collection' => $this->getCollectedBy($model),
            'builder'    => $this->getBuilder($model),
            'resource'   => $this->getResource($model),
        ];
    }

    /**
     * Récupère les attributs de colonne pour le modèle donné.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function getAttributes(Model $model): Collection
    {
        $connection = $model->getConnection();
        $table      = $model->getTable();
        $columns    = $connection->getColumnData($table);
        $indexes    = $connection->getIndexData($table);

        return (new Collection($columns))
            ->map(fn ($column) => [
                'name'       => $column->name,
                'type'       => $column->type,
                'increments' => $column->auto_increment,
                'nullable'   => $column->nullable,
                'default'    => $this->getColumnDefault($column, $model),
                'unique'     => $this->columnIsUnique($column->name, $indexes),
                'fillable'   => $model->isFillable($column->name),
                'hidden'     => $this->attributeIsHidden($column->name, $model),
                'appended'   => null,
                'cast'       => $this->getCastType($column->name, $model),
            ])
            ->merge($this->getVirtualAttributes($model, $columns));
    }

    /**
     * Récupère les attributs virtuaux (non-colonne) pour le modèle donné.
     */
    protected function getVirtualAttributes(Model $model, array $columns): Collection
    {
        $class = new ReflectionClass($model);

        return (new Collection($class->getMethods()))
            ->reject(
                fn (ReflectionMethod $method) => $method->isStatic()
                    || $method->isAbstract()
                    || $method->getDeclaringClass()->getName() === Model::class
            )
            ->mapWithKeys(function (ReflectionMethod $method) use ($model) {
                if (preg_match('/^get(.+)Attribute$/', $method->getName(), $matches) === 1) {
                    return [Text::snake($matches[1]) => 'accessor'];
                } 
                if ($model->hasAttributeMutator($method->getName())) {
                    return [Text::snake($method->getName()) => 'attribute'];
                }
                return [];
            })
            ->reject(fn ($cast, $name) => (new Collection($columns))->contains('name', $name))
            ->map(fn ($cast, $name) => [
                'name' => $name,
                'type' => null,
                'increments' => false,
                'nullable' => null,
                'default' => null,
                'unique' => null,
                'fillable' => $model->isFillable($name),
                'hidden' => $this->attributeIsHidden($name, $model),
                'appended' => $model->hasAppended($name),
                'cast' => $cast,
            ])
            ->values();
    }

    /**
     * Récupère les relations du modèle donné.
     */
    protected function getRelations(Model $model): Collection
    {
        return (new Collection(get_class_methods($model)))
            ->map(fn ($method) => new ReflectionMethod($model, $method))
            ->reject(
                fn (ReflectionMethod $method) => $method->isStatic()
                    || $method->isAbstract()
                    || $method->getDeclaringClass()->getName() === Model::class
                    || $method->getNumberOfParameters() > 0
            )
            ->filter(function (ReflectionMethod $method) {
                if ($method->getReturnType() instanceof ReflectionNamedType
                    && is_subclass_of($method->getReturnType()->getName(), Relation::class)) {
                    return true;
                }

                $file = new SplFileObject($method->getFileName());
                $file->seek($method->getStartLine() - 1);
                $code = '';
                while ($file->key() < $method->getEndLine()) {
                    $code .= trim($file->current());
                    $file->next();
                }

                return (new Collection($this->relationMethods))
                    ->contains(fn ($relationMethod) => str_contains($code, '$this->'.$relationMethod.'('));
            })
            ->map(function (ReflectionMethod $method) use ($model) {
                $relation = $method->invoke($model);

                if (! $relation instanceof Relation) {
                    return null;
                }

                return [
                    'name' => $method->getName(),
                    'type' => Text::afterLast(get_class($relation), '\\'),
                    'related' => get_class($relation->getRelated()),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Récupère la première politique associée à ce modèle.
     */
    protected function getPolicy(Model $model): ?string
    {
        return null;

        /*
            $policy = Gate::getPolicyFor($model::class);

            return $policy ? $policy::class : null;
        */
    }

    /**
     * Récupère les événements que le modèle déclenche.
     */
    protected function getEvents(Model $model): Collection
    {
        return (new Collection($model->dispatchesEvents()))
            ->map(fn (string $class, string $event) => [
                'event' => $event,
                'class' => $class,
            ])->values();
    }

    /**
     * Récupère les observateurs qui surveillent ce modèle.
     */
    protected function getObservers(Model $model): Collection
    {
        $listeners = service('event')->getListeners();

        // Récupère les observateurs Wolke pour ce modèle...
        $listeners = array_filter($listeners, function ($v, $key) use ($model) {
            return Text::startsWith($key, 'wolke.') && Text::endsWith($key, $model::class);
        }, ARRAY_FILTER_USE_BOTH);

        // Formate les verbes des écouteurs Wolke => méthodes de l'observateur...
        $extractVerb = function ($key) {
            preg_match('/wolke.([a-zA-Z]+)\: /', $key, $matches);

            return $matches[1] ?? '?';
        };

        $formatted = [];

        foreach ($listeners as $key => $observerMethods) {
            $formatted[] = [
                'event' => $extractVerb($key),
                'observer' => array_map(fn ($obs) => is_string($obs) ? $obs : 'Closure', $observerMethods),
            ];
        }

        return new Collection($formatted);
    }

    /**
     * Récupère la classe de collection utilisée par le modèle.
     *
     * @return class-string<\BlitzPHP\Wolke\Collection>
     */
    protected function getCollectedBy(Model $model): string
    {
        return $model->newCollection()::class;
    }

    /**
     * Récupère la classe de constructeur de requêtes utilisée par le modèle.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * 
     * @return class-string<Builder<TModel>>
     */
    protected function getBuilder($model)
    {
        return $model->newQuery()::class;
    }

    /**
     * Récupère la classe utilisée pour la transformation des réponses JSON.
     *
     * @return \Illuminate\Http\Resources\Json\JsonResource|null
     */
    protected function getResource(Model $model)
    {
        return null;
        // return rescue(static fn () => $model->toResource()::class, null, false);
    }

    /**
     * Qualifie le nom de base de la classe du modèle donné.
     *
     * @return class-string<Model>
     */
    protected function qualifyModel(string $model): string
    {
        if (str_contains($model, '\\') && class_exists($model)) {
            return $model;
        }

        $model = ltrim($model, '\\/');

        $model = str_replace('/', '\\', $model);

        $rootNamespace = APP_NAMESPACE . '\\';

        if (Text::startsWith($model, $rootNamespace)) {
            return $model;
        }

        return is_dir(app_path('Entities'))
            ? $rootNamespace.'Entities\\'.$model
            : $rootNamespace.$model;
    }

    /**
     * Récupère le type de cast pour la colonne donnée.
     */
    protected function getCastType(string $column, Model $model): ?string
    {
        if ($model->hasGetMutator($column) || $model->hasSetMutator($column)) {
            return 'accessor';
        }

        if ($model->hasAttributeMutator($column)) {
            return 'attribute';
        }

        return $this->getCastsWithDates($model)->get($column) ?? null;
    }

    /**
     * Récupère les casts du modèle, y compris les casts de dates.
     */
    protected function getCastsWithDates(Model $model): Collection
    {
        return (new Collection($model->getDates()))
            ->filter()
            ->flip()
            ->map(fn () => 'datetime')
            ->merge($model->getCasts());
    }

    /**
     * Détermine si l'attribut donné est caché.
     */
    protected function attributeIsHidden(string $attribute, Model $model): bool
    {
        if (count($model->getHidden()) > 0) {
            return in_array($attribute, $model->getHidden());
        }

        if (count($model->getVisible()) > 0) {
            return ! in_array($attribute, $model->getVisible());
        }

        return false;
    }

    /**
     * Récupère la valeur par défaut pour la colonne donnée.
     */
    protected function getColumnDefault(stdClass $column, Model $model): mixed
    {
        $attributeDefault = $model->getAttributes()[$column->name] ?? null;

        return Helpers::enumValue($attributeDefault) ?? $column->default;
    }

    /**
     * Détermine si l'attribut donné est unique.
     */
    protected function columnIsUnique(string $column, array $indexes): bool
    {
        return (new Collection($indexes))->contains(
            fn ($index) => count($index->columns) === 1 && $index->columns[0] === $column && $index->unique
        );
    }
}
