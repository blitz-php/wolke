<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Concerns;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Concerns\GuardsAttributes</a>
 */
trait GuardsAttributes
{
    /**
     * Les attributs qui sont assignables en masse.
     *
     * @var list<string>
     */
    protected array $fillable = [];

    /**
     * Les attributs qui ne sont pas assignables en masse.
     *
     * @var list<string>
     */
    protected array $guarded = ['*'];

    /**
     * Indique si toutes les assignations en masse sont activées.
     */
    protected static bool $unguarded = false;

    /**
     * Les colonnes réelles qui existent dans la base de données et qui peuvent être protégées.
     * 
     * @var array<class-string,list<string>>
     */
    protected static array $guardableColumns = [];

    /**
     * Obtient les attributs fillable pour le modèle.
     */
    public function getFillable(): array
    {
        return $this->fillable;
    }

    /**
     * Définit les attributs fillable pour le modèle.
     */
    public function fillable(array $fillable): self
    {
        $this->fillable = $fillable;

        return $this;
    }

    /**
     * Fusionne de nouveaux attributs fillable avec les attributs fillable existants sur le modèle.
     */
    public function mergeFillable(array $fillable): self
    {
        $this->fillable = array_values(array_unique(array_merge($this->fillable, $fillable)));

        return $this;
    }

    /**
     * Obtient les attributs guarded pour le modèle.
     *
     * @return list<string>
     */
    public function getGuarded(): array
    {
        return self::$unguarded === true
            ? []
            : $this->guarded;
    }

    /**
     * Définit les attributs guarded pour le modèle.
     *
     * @param list<string> $guarded
     */
    public function guard(array $guarded): self
    {
        $this->guarded = $guarded;

        return $this;
    }

    /**
     * Fusionne de nouveaux attributs guarded avec les attributs guarded existants sur le modèle.
     *
     * @param list<string> $guarded
     */
    public function mergeGuarded(array $guarded): self
    {
        $this->guarded = array_values(array_unique(array_merge($this->guarded, $guarded)));

        return $this;
    }

    /**
     * Désactive toutes les restrictions d'assignation en masse.
     */
    public static function unguard(bool $state = true)
    {
        static::$unguarded = $state;
    }

    /**
     * Active les restrictions d'assignation en masse.
     */
    public static function reguard()
    {
        static::$unguarded = false;
    }

    /**
     * Détermine si l'état actuel est "unguarded".
     */
    public static function isUnguarded(): bool
    {
        return static::$unguarded;
    }

    /**
     * Exécute le callable donné tout en étant non protégé.
     */
    public static function unguarded(callable $callback): mixed
    {
        if (static::$unguarded) {
            return $callback();
        }

        static::unguard();

        try {
            return $callback();
        } finally {
            static::reguard();
        }
    }

    /**
     * Détermine si l'attribut donné peut être assigné en masse.
     */
    public function isFillable(string $key): bool
    {
        if (static::$unguarded) {
            return true;
        }

        // Si la clé est dans le tableau "fillable", nous pouvons bien sûr supposer qu'il s'agit
        // d'un attribut fillable. Sinon, nous vérifierons le tableau guarded quand
        // nous aurons besoin de déterminer si l'attribut est sur la liste noire du modèle.
        if (in_array($key, $this->getFillable(), true)) {
            return true;
        }

        // Si l'attribut est explicitement listé dans le tableau "guarded", nous pouvons
        // retourner false immédiatement. Cela signifie que cet attribut n'est définitivement pas
        // fillable et il n'y a aucun intérêt à aller plus loin dans cette méthode.
        if ($this->isGuarded($key)) {
            return false;
        }

        return empty($this->getFillable())
            && ! str_contains($key, '.')
            && ! str_starts_with($key, '_');
    }

    /**
     * Détermine si la clé donnée est gardée.
     */
    public function isGuarded(string $key): bool
    {
        if (empty($this->getGuarded())) {
            return false;
        }

        return $this->getGuarded() === ['*']
               || ! empty(preg_grep('/^' . preg_quote($key) . '$/i', $this->getGuarded()))
               || ! $this->isGuardableColumn($key);
    }

    /**
     * Détermine si la colonne donnée est une colonne valide et protégeable.
     */
    protected function isGuardableColumn(string $key): bool
    {
        if ($this->hasSetMutator($key) || $this->hasAttributeSetMutator($key) || $this->isClassCastable($key)) {
            return true;
        }

        if (! isset(static::$guardableColumns[static::class])) {
            $columns = $this->getConnection()
                ->getSchemaBuilder()
                ->getColumnListing($this->getTable());

            if (empty($columns)) {
                return true;
            }

            static::$guardableColumns[static::class] = $columns;
        }

        return in_array($key, static::$guardableColumns[static::class], true);
    }

    /**
     * Détermine si le modèle est totalement gardé.
     */
    public function totallyGuarded(): bool
    {
        return count($this->getFillable()) === 0 && $this->getGuarded() === ['*'];
    }

    /**
     * Obtient les attributs fillable d'un tableau donné.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function fillableFromArray(array $attributes): array
    {
        if (count($this->getFillable()) > 0 && ! static::$unguarded) {
            return array_intersect_key($attributes, array_flip($this->getFillable()));
        }

        return $attributes;
    }
}
