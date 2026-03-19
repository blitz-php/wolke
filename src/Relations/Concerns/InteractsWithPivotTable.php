<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Relations\Concerns;

use BackedEnum;
use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Pivot;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\Concerns\InteractsWithPivotTable</a>
 */
trait InteractsWithPivotTable
{
    /**
     * Bascule un modèle (ou des modèles) depuis le parent.
     *
     * Chaque modèle existant est détaché, et les non existants sont attachés.
     */
    public function toggle(mixed $ids, bool $touch = true): array
    {
        $changes = [
            'attached' => [], 'detached' => [],
        ];

        $records = $this->formatRecordsList($this->parseIds($ids));

        // Ensuite, nous déterminerons quels IDs doivent être supprimés de la table de jointure en
        // vérifiant lesquels des IDs/enregistrements donnés se trouvent dans la liste des enregistrements actuels
        // et en supprimant toutes ces lignes de cette table de jointure "intermédiaire".
        $detach = array_values(array_intersect(
            $this->newPivotQuery()->values($this->relatedPivotKey),
            array_keys($records),
        ));

        if (count($detach) > 0) {
            $this->detach($detach, false);

            $changes['detached'] = $this->castKeys($detach);
        }

        // Enfin, pour tous les enregistrements qui n'ont pas été "détachés", nous attacherons les
        // enregistrements dans la table intermédiaire. Ensuite, nous ajouterons ces attaches à
        // cette liste de modifications et nous préparerons à retourner ces résultats aux appelants.
        $attach = array_diff_key($records, array_flip($detach));

        if (count($attach) > 0) {
            $this->attach($attach, [], false);

            $changes['attached'] = array_keys($attach);
        }

        // Une fois que nous avons fini d'attacher ou de détacher les enregistrements, nous verrons si nous
        // avons fait des attaches ou des détachements, et si c'est le cas, nous toucherons ces
        // relations si elles sont configurées pour être touchées lors des mises à jour de la base de données.
        if (
            $touch && (count($changes['attached'])
                       || count($changes['detached']))
        ) {
            $this->touchIfTouching();
        }

        return $changes;
    }

    /**
     * Synchronise les tables intermédiaires avec une liste d'IDs sans détacher.
     *
     * @param array|int|IterableCollection|Model|string $ids
     *
     * @return array{attached: array, detached: array, updated: array}
     */
    public function syncWithoutDetaching($ids): array
    {
        return $this->sync($ids, false);
    }

    /**
     * Synchronise les tables intermédiaires avec une liste d'IDs ou une collection de modèles.
     *
     * @param array|int|IterableCollection|Model|string $ids
     *
     * @return array{attached: array, detached: array, updated: array}
     */
    public function sync($ids, bool $detaching = true): array
    {
        $changes = [
            'attached' => [], 'detached' => [], 'updated' => [],
        ];

        $records = $this->formatRecordsList($this->parseIds($ids));

        if ($records === [] && ! $detaching) {
            return $changes;
        }

        // Nous devons d'abord attacher tous les modèles associés qui ne sont pas actuellement
        // dans cette table de jointure. Nous parcourrons les IDs donnés, en vérifiant
        // s'ils existent dans le tableau des IDs actuels, et sinon nous insérerons.
        $current = $this->getCurrentlyAttachedPivots()
            ->pluck($this->relatedPivotKey)->all();

        // Ensuite, nous prendrons les différences des IDs actuels et donnés et détacherons
        // toutes les entités qui existent dans le tableau "actuel" mais qui ne sont pas dans le
        // tableau des nouveaux IDs donnés à la méthode, ce qui complétera la synchronisation.
        if ($detaching) {
            $detach = array_diff($current, array_keys($records));

            if (count($detach) > 0) {
                $this->detach($detach, false);

                $changes['detached'] = $this->castKeys($detach);
            }
        }

        // Maintenant, nous sommes enfin prêts à attacher les nouveaux enregistrements. Notez que nous désactiverons
        // le touch jusqu'à ce que toute l'opération soit terminée afin de ne pas déclencher
        // une tonne d'opérations de touch jusqu'à ce que nous ayons totalement fini de synchroniser les enregistrements.
        $changes = array_merge(
            $changes,
            $this->attachNew($records, $current, false),
        );

        // Une fois que nous avons fini d'attacher ou de détacher les enregistrements, nous verrons si nous
        // avons fait des attaches ou des détachements, et si c'est le cas, nous toucherons ces
        // relations si elles sont configurées pour être touchées lors des mises à jour de la base de données.
        if (count($changes['attached'])
            || count($changes['updated'])
            || count($changes['detached'])) {
            $this->touchIfTouching();
        }

        return $changes;
    }

    /**
     * Synchronise les tables intermédiaires avec une liste d'IDs ou une collection de modèles avec les valeurs pivot données.
     *
     * @param array|int|IterableCollection|Model|string $ids
     *
     * @return array{attached: array, detached: array, updated: array}
     */
    public function syncWithPivotValues($ids, array $values, bool $detaching = true): array
    {
        return $this->sync(
            (new IterableCollection($this->parseIds($ids)))->mapWithKeys(static fn ($id) => [$id => $values]),
            $detaching,
        );
    }

    /**
     * Formate la liste d'enregistrements de synchronisation/basculement pour qu'elle soit indexée par ID.
     */
    protected function formatRecordsList(array $records): array
    {
        return (new IterableCollection($records))->mapWithKeys(static function ($attributes, $id) {
            if (! is_array($attributes)) {
                [$id, $attributes] = [$attributes, []];
            }

            if ($id instanceof BackedEnum) {
                $id = $id->value;
            }

            return [$id => $attributes];
        })->all();
    }

    /**
     * Attache tous les enregistrements qui ne sont pas dans la liste des enregistrements actuels donnés.
     */
    protected function attachNew(array $records, array $current, bool $touch = true): array
    {
        $changes = ['attached' => [], 'updated' => []];

        foreach ($records as $id => $attributes) {
            // Si l'ID n'est pas dans la liste des IDs pivot existants, nous insérerons un nouvel enregistrement pivot,
            // sinon, nous mettrons simplement à jour cet enregistrement existant sur cette table de jointure,
            // afin que les développeurs puissent facilement mettre à jour ces enregistrements sans douleur.
            if (! in_array($id, $current, true)) {
                $this->attach($id, $attributes, $touch);

                $changes['attached'][] = $this->castKey($id);
            }

            // Maintenant, nous allons essayer de mettre à jour un enregistrement pivot existant avec les attributs qui ont été
            // donnés à la méthode. Si le modèle est réellement mis à jour, nous l'ajouterons à la
            // liste des enregistrements pivot mis à jour pour les retourner au consommateur.
            elseif (
                count($attributes) > 0
                && $this->updateExistingPivot($id, $attributes, $touch)
            ) {
                $changes['updated'][] = $this->castKey($id);
            }
        }

        return $changes;
    }

    /**
     * Met à jour un enregistrement pivot existant sur la table.
     */
    public function updateExistingPivot(mixed $id, array $attributes, bool $touch = true): int
    {
        if ($this->using) {
            return $this->updateExistingPivotUsingCustomClass($id, $attributes, $touch);
        }

        if ($this->hasPivotColumn($this->updatedAt())) {
            $attributes = $this->addTimestampsToAttachment($attributes, true);
        }

        $updated = $this->newPivotStatementForId($id)->update(
            $this->castAttributes($attributes),
        );

        if ($touch) {
            $this->touchIfTouching();
        }

        return $updated;
    }

    /**
     * Met à jour un enregistrement pivot existant sur la table via une classe personnalisée.
     */
    protected function updateExistingPivotUsingCustomClass(mixed $id, array $attributes, bool $touch): int
    {
        $pivot = $this->getCurrentlyAttachedPivotsForIds($id)->first();

        $updated = $pivot ? $pivot->fill($attributes)->isDirty() : false;

        if ($updated) {
            $pivot->save();
        }

        if ($touch) {
            $this->touchIfTouching();
        }

        return (int) $updated;
    }

    /**
     * Attache un modèle au parent.
     */
    public function attach(mixed $id, array $attributes = [], bool $touch = true): void
    {
        if ($this->using) {
            $this->attachUsingCustomClass($id, $attributes);
        } else {
            // Ici, nous insérerons les enregistrements d'attachement dans la table pivot. Une fois que nous avons
            // inséré les enregistrements, nous toucherons les relations si nécessaire et la
            // fonction retournera. Nous pouvons analyser les IDs avant d'insérer les enregistrements.
            $this->newPivotStatement()->bulkInsert($this->formatAttachRecords(
                $this->parseIds($id),
                $attributes,
            ));
        }

        if ($touch) {
            $this->touchIfTouching();
        }
    }

    /**
     * Attache un modèle au parent en utilisant une classe personnalisée.
     */
    protected function attachUsingCustomClass(mixed $id, array $attributes): void
    {
        $records = $this->formatAttachRecords(
            $this->parseIds($id),
            $attributes,
        );

        foreach ($records as $record) {
            $this->newPivot($record, false)->save();
        }
    }

    /**
     * Crée un tableau d'enregistrements à insérer dans la table pivot.
     */
    protected function formatAttachRecords(array $ids, array $attributes): array
    {
        $records = [];

        $hasTimestamps = ($this->hasPivotColumn($this->createdAt())
                  || $this->hasPivotColumn($this->updatedAt()));

        // Pour créer les enregistrements d'attachement, nous parcourrons simplement les IDs donnés
        // et créerons un nouvel enregistrement à insérer pour chaque ID. Chaque ID peut en fait être une
        // clé dans le tableau, avec des attributs supplémentaires à placer dans d'autres colonnes.
        foreach ($ids as $key => $value) {
            $records[] = $this->formatAttachRecord(
                $key,
                $value,
                $attributes,
                $hasTimestamps,
            );
        }

        return $records;
    }

    /**
     * Crée une charge utile complète d'enregistrement d'attachement.
     */
    protected function formatAttachRecord(int $key, mixed $value, array $attributes, bool $hasTimestamps): array
    {
        [$id, $attributes] = $this->extractAttachIdAndAttributes($key, $value, $attributes);

        return array_merge(
            $this->baseAttachRecord($id, $hasTimestamps),
            $this->castAttributes($attributes),
        );
    }

    /**
     * Obtient l'ID de l'enregistrement d'attachement et les attributs supplémentaires.
     */
    protected function extractAttachIdAndAttributes(mixed $key, mixed $value, array $attributes): array
    {
        return is_array($value)
                    ? [$key, array_merge($value, $attributes)]
                    : [$value, $attributes];
    }

    /**
     * Crée un nouvel enregistrement d'attachement pivot.
     */
    protected function baseAttachRecord(int|string $id, bool $timed): array
    {
        $record[$this->relatedPivotKey] = $id;

        $record[$this->foreignPivotKey] = $this->parent->{$this->parentKey};

        // Si l'enregistrement a besoin d'avoir des horodatages de création et de mise à jour, nous les ferons
        // en appelant la méthode "freshTimestamp" du modèle parent qui nous
        // fournira un horodatage frais dans le format préféré de ce modèle.
        if ($timed) {
            $record = $this->addTimestampsToAttachment($record);
        }

        foreach ($this->pivotValues as $value) {
            $record[$value['column']] = $value['value'];
        }

        return $record;
    }

    /**
     * Définit les horodatages de création et de mise à jour sur un enregistrement d'attachement.
     */
    protected function addTimestampsToAttachment(array $record, bool $exists = false): array
    {
        $fresh = $this->parent->freshTimestamp();

        if ($this->using) {
            $using      = $this->using;
            $pivotModel = new $using();

            $fresh = $fresh->format($pivotModel->getDateFormat());
        }

        if (! $exists && $this->hasPivotColumn($this->createdAt())) {
            $record[$this->createdAt()] = $fresh;
        }

        if ($this->hasPivotColumn($this->updatedAt())) {
            $record[$this->updatedAt()] = $fresh;
        }

        return $record;
    }

    /**
     * Détermine si la colonne donnée est définie comme une colonne pivot.
     */
    public function hasPivotColumn(string $column): bool
    {
        return in_array($column, $this->pivotColumns, true);
    }

    /**
     * Détache les modèles de la relation.
     */
    public function detach(mixed $ids = null, bool $touch = true): int
    {
        if ($this->using) {
            $results = $this->detachUsingCustomClass($ids);
        } else {
            $query = $this->newPivotQuery();

            // Si des IDs associés ont été passés à la méthode, nous ne supprimerons que ces
            // associations, sinon tous les liens d'association seront rompus.
            // Nous retournerons le nombre de lignes affectées lorsque nous ferons les suppressions.
            if (null !== $ids) {
                $ids = $this->parseIds($ids);

                if ($ids === []) {
                    return 0;
                }

                $query->whereIn($this->getQualifiedRelatedPivotKeyName(), $ids);
            }

            // Une fois que nous avons toutes les conditions définies sur la déclaration, nous sommes prêts
            // à exécuter la suppression sur la table pivot. Ensuite, si le paramètre touch
            // est vrai, nous irons toucher tous les modèles liés pour synchroniser.
            $results = $query->delete();
        }

        if ($touch) {
            $this->touchIfTouching();
        }

        return $results;
    }

    /**
     * Détache les modèles de la relation en utilisant une classe personnalisée.
     */
    protected function detachUsingCustomClass(mixed $ids): int
    {
        $results = 0;
        $records = $this->getCurrentlyAttachedPivotsForIds($ids);

        foreach ($records as $record) {
            $results += $record->delete();
        }

        return $results;
    }

    /**
     * Obtient les modèles pivot actuellement attachés.
     */
    protected function getCurrentlyAttachedPivots(): IterableCollection
    {
        return $this->getCurrentlyAttachedPivotsForIds();
    }

    /**
     * Obtient les modèles pivot actuellement attachés, filtrés par les clés du modèle lié.
     */
    protected function getCurrentlyAttachedPivotsForIds(mixed $ids = null): IterableCollection
    {
        return $this->newPivotQuery()
            ->when($ids !== null, fn ($query) => $query->whereIn(
                $this->getQualifiedRelatedPivotKeyName(),
                $this->parseIds($ids),
            ))
            ->collect()
            ->map(function ($record) {
                $class = $this->using ?: Pivot::class;

                $pivot = $class::fromRawAttributes($this->parent, (array) $record, $this->getTable(), true);

                return $pivot
                    ->setPivotKeys($this->foreignPivotKey, $this->relatedPivotKey)
                    ->setRelatedModel($this->related);
            });
    }

    /**
     * Crée une nouvelle instance de modèle pivot.
     */
    public function newPivot(array $attributes = [], bool $exists = false): Pivot
    {
        $attributes = array_merge(array_column($this->pivotValues, 'value', 'column'), $attributes);

        $pivot = $this->related->newPivot(
            $this->parent,
            $attributes,
            $this->table,
            $exists,
            $this->using,
        );

        return $pivot
            ->setPivotKeys($this->foreignPivotKey, $this->relatedPivotKey)
            ->setRelatedModel($this->related);
    }

    /**
     * Crée une nouvelle instance de modèle pivot existant.
     */
    public function newExistingPivot(array $attributes = []): Pivot
    {
        return $this->newPivot($attributes, true);
    }

    /**
     * Obtient un nouveau constructeur de requête simple pour la table pivot.
     */
    public function newPivotStatement(): BaseBuilder
    {
        return (clone $this->query->getQuery())->reset()->from($this->table);
    }

    /**
     * Obtient une nouvelle déclaration pivot pour un ID "autre" donné.
     */
    public function newPivotStatementForId(mixed $id): BaseBuilder
    {
        return $this->newPivotQuery()->whereIn(
            $this->getQualifiedRelatedPivotKeyName(),
            $this->parseIds($id),
        );
    }

    /**
     * Crée un nouveau constructeur de requête pour la table pivot.
     */
    public function newPivotQuery(): BaseBuilder
    {
        $query = $this->newPivotStatement();

        foreach ($this->pivotWheres as $arguments) {
            $query->where(...$arguments);
        }

        foreach ($this->pivotWhereIns as $arguments) {
            $query->whereIn(...$arguments);
        }

        foreach ($this->pivotWhereNulls as $arguments) {
            $query->whereNull(...$arguments);
        }

        return $query->where(
            $this->getQualifiedForeignPivotKeyName(),
            $this->parent->{$this->parentKey},
        );
    }

    /**
     * Définit les colonnes de la table pivot à récupérer.
     *
     * @param array|mixed $columns
     */
    public function withPivot(mixed $columns): static
    {
        $this->pivotColumns = array_merge(
            $this->pivotColumns,
            is_array($columns) ? $columns : func_get_args(),
        );

        return $this;
    }

    /**
     * Obtient tous les IDs de la valeur mixte donnée.
     */
    protected function parseIds(mixed $value): array
    {
        if ($value instanceof Model) {
            return [$value->{$this->relatedKey}];
        }

        if ($value instanceof Collection) {
            return $value->pluck($this->relatedKey)->all();
        }

        if ($value instanceof IterableCollection || is_array($value)) {
            return (new IterableCollection($value))
                ->map(fn ($item) => $item instanceof Model ? $item->{$this->relatedKey} : $item)
                ->all();
        }

        return (array) $value;
    }

    /**
     * Obtient l'ID de la valeur mixte donnée.
     */
    protected function parseId(mixed $value): mixed
    {
        return $value instanceof Model ? $value->{$this->relatedKey} : $value;
    }

    /**
     * Convertit les clés données en entiers si elles sont numériques, sinon en chaînes.
     */
    protected function castKeys(array $keys): array
    {
        return array_map(fn ($v) => $this->castKey($v), $keys);
    }

    /**
     * Convertit la clé donnée pour la convertir en type de clé primaire.
     */
    protected function castKey(mixed $key): mixed
    {
        return $this->getTypeSwapValue(
            $this->related->getKeyType(),
            $key,
        );
    }

    /**
     * Convertit les attributs pivot donnés.
     */
    protected function castAttributes(array $attributes): array
    {
        return $this->using
                    ? $this->newPivot()->fill($attributes)->getAttributes()
                    : $attributes;
    }

    /**
     * Convertit une valeur donnée en un type de valeur donné.
     */
    protected function getTypeSwapValue(string $type, mixed $value): mixed
    {
        return match (strtolower($type)) {
            'int', 'integer' => (int) $value,
            'real', 'float', 'double' => (float) $value,
            'string' => (string) $value,
            default  => $value,
        };
    }
}
