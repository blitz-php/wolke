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

use BackedEnum;
use BlitzPHP\Contracts\Security\EncrypterInterface;
use BlitzPHP\Contracts\Support\Arrayable;
use BlitzPHP\Utilities\Date;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Casts\AsArrayObject;
use BlitzPHP\Wolke\Casts\AsCollection;
use BlitzPHP\Wolke\Casts\AsEncryptedArrayObject;
use BlitzPHP\Wolke\Casts\AsEncryptedCollection;
use BlitzPHP\Wolke\Casts\AsEnumArrayObject;
use BlitzPHP\Wolke\Casts\AsEnumCollection;
use BlitzPHP\Wolke\Casts\AsIntBool;
use BlitzPHP\Wolke\Casts\Attribute;
use BlitzPHP\Wolke\Casts\Json;
use BlitzPHP\Wolke\Contracts\Castable;
use BlitzPHP\Wolke\Contracts\CastsInboundAttributes;
use BlitzPHP\Wolke\Exceptions\InvalidCastException;
use BlitzPHP\Wolke\Exceptions\JsonEncodingException;
use BlitzPHP\Wolke\Exceptions\LazyLoadingViolationException;
use BlitzPHP\Wolke\Exceptions\MissingAttributeException;
use BlitzPHP\Wolke\Relations\Relation;
use DateTimeInterface;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use UnitEnum;

trait HasAttributes
{
    /**
     * The model's attributes.
     */
    protected array $attributes = [];

    /**
     * The model attribute's original state.
     */
    protected array $original = [];

    /**
     * The changed model attributes.
     */
    protected array $changes = [];

    /**
     * The attributes that should be cast.
     */
    protected array $casts = [];

    /**
     * The attributes that have been cast using custom classes.
     */
    protected array $classCastCache = [];

    /**
     * The attributes that have been cast using "Attribute" return type mutators.
     */
    protected array $attributeCastCache = [];

    /**
     * The built-in, primitive cast types supported by Eloquent.
     *
     * @var string[]
     */
    protected static array $primitiveCastTypes = [
        'array',
        'bool',
        'boolean',
        'collection',
        'custom_datetime',
        'date',
        'datetime',
        'decimal',
        'double',
        'encrypted',
        'encrypted:array',
        'encrypted:collection',
        'encrypted:json',
        'encrypted:object',
        'float',
        'hashed',
        'int',
        'integer',
        'json',
        'object',
        'real',
        'string',
        'timestamp',
    ];

    /**
     * The storage format of the model's date columns.
     */
    protected string $dateFormat = 'Y-m-d H:i:s';

    /**
     * The accessors to append to the model's array form.
     */
    protected array $appends = [];

    /**
     * Indicates whether attributes are snake cased on arrays.
     */
    public static bool $snakeAttributes = true;

    /**
     * The cache of the mutated attributes for each class.
     */
    protected static array $mutatorCache = [];

    /**
     * The cache of the "Attribute" return type marked mutated attributes for each class.
     */
    protected static array $attributeMutatorCache = [];

    /**
     * The cache of the "Attribute" return type marked mutated, gettable attributes for each class.
     */
    protected static array $getAttributeMutatorCache = [];

    /**
     * The cache of the "Attribute" return type marked mutated, settable attributes for each class.
     */
    protected static array $setAttributeMutatorCache = [];

    /**
     * The cache of the converted cast types.
     */
    protected static array $castTypeCache = [];

    /**
     * The encrypter instance that is used to encrypt attributes.
     *
     * @var EncrypterInterface
     */
    public static $encrypter;

    /**
     * Convertissez les attributs du modèle en tableau.
     */
    public function attributesToArray(): array
    {
        // Si un attribut est une date, nous le convertirons en chaîne après l'avoir converti en une instance DateTime / Carbon. C'est ainsi que nous obtiendrons un formatage cohérent lors de l'accès aux attributs.
        $attributes = $this->addDateAttributesToArray(
            $attributes = $this->getArrayableAttributes()
        );

        $attributes = $this->addMutatedAttributesToArray(
            $attributes,
            $mutatedAttributes = $this->getMutatedAttributes()
        );

        // Ensuite, nous allons gérer tous les transtypages qui ont été configurés pour ce modèle et convertir les valeurs en leur type approprié. Si l'attribut a un mutateur, nous n'effectuerons pas le cast sur ces attributs pour éviter toute confusion.
        $attributes = $this->addCastAttributesToArray(
            $attributes,
            $mutatedAttributes
        );

        // Ici, nous allons saisir tous les attributs calculés ajoutés à ce modèle car ces attributs ne sont pas vraiment dans le tableau d'attributs, mais sont exécutés lorsque nous avons besoin de mettre en tableau ou JSON le modèle pour plus de commodité pour le codeur.
        foreach ($this->getArrayableAppends() as $key) {
            $attributes[$key] = $this->mutateAttributeForArray($key, null);
        }

        return $attributes;
    }

    /**
     * Ajoutez les attributs de date au tableau d'attributs.
     */
    protected function addDateAttributesToArray(array $attributes): array
    {
        foreach ($this->getDates() as $key) {
            if (! isset($attributes[$key])) {
                continue;
            }

            $attributes[$key] = $this->serializeDate(
                $this->asDateTime($attributes[$key])
            );
        }

        return $attributes;
    }

    /**
     * Ajoutez les attributs mutés au tableau d'attributs.
     */
    protected function addMutatedAttributesToArray(array $attributes, array $mutatedAttributes): array
    {
        foreach ($mutatedAttributes as $key) {
            // Nous voulons parcourir tous les attributs mutés pour ce modèle et appeler le mutateur pour l'attribut.
            // Nous mettons en cache tous les attributs mutés afin de ne pas avoir à vérifier constamment les attributs qui changent réellement.
            if (! array_key_exists($key, $attributes)) {
                continue;
            }

            // Ensuite, nous appellerons le mutateur pour cet attribut afin que nous puissions obtenir les valeurs réelles de ces attributs mutés.
            // Après avoir fini de muter chacun des attributs, nous renverrons ce tableau final des attributs mutés.
            $attributes[$key] = $this->mutateAttributeForArray(
                $key,
                $attributes[$key]
            );
        }

        return $attributes;
    }

    /**
     * Ajoutez les attributs castés au tableau d'attributs.
     */
    protected function addCastAttributesToArray(array $attributes, array $mutatedAttributes): array
    {
        foreach ($this->getCasts() as $key => $value) {
            if (
                ! array_key_exists($key, $attributes)
                || in_array($key, $mutatedAttributes, true)
            ) {
                continue;
            }

            // Ici, nous allons caster l'attribut.
            // Ensuite, si le cast est un cast date ou datetime, nous sérialiserons la date pour le tableau.
            // Cela convertira les dates en chaînes basées sur le format de date spécifié pour ces modèles Wolke.
            $attributes[$key] = $this->castAttribute(
                $key,
                $attributes[$key]
            );

            // Si l'attribut cast était une date ou une date/heure, nous sérialiserons la date sous forme de chaîne.
            // Cela permet aux développeurs de personnaliser la façon dont les dates sont sérialisées dans un tableau sans affecter la façon dont elles sont conservées dans le stockage.
            if (isset($attributes[$key]) && in_array($value, ['date', 'datetime'], true)) {
                $attributes[$key] = $this->serializeDate($attributes[$key]);
            }

            if (isset($attributes[$key]) && ($this->isCustomDateTimeCast($value))) {
                $attributes[$key] = $attributes[$key]->format(explode(':', $value, 2)[1]);
            }

            if (
                $attributes[$key] && $attributes[$key] instanceof DateTimeInterface
                && $this->isClassCastable($key)
            ) {
                $attributes[$key] = $this->serializeDate($attributes[$key]);
            }

            if (isset($attributes[$key]) && $this->isClassSerializable($key)) {
                $attributes[$key] = $this->serializeClassCastableAttribute($key, $attributes[$key]);
            }

            if ($this->isEnumCastable($key) && (! ($attributes[$key] ?? null) instanceof Arrayable)) {
                $attributes[$key] = isset($attributes[$key]) ? $this->getStorableEnumValue($attributes[$key]) : null;
            }

            if ($attributes[$key] instanceof Arrayable) {
                $attributes[$key] = $attributes[$key]->toArray();
            }
        }

        return $attributes;
    }

    /**
     * Obtenez un tableau d'attributs de tous les attributs pouvant être mis en tableau.
     */
    protected function getArrayableAttributes(): array
    {
        return $this->getArrayableItems($this->getAttributes());
    }

    /**
     * Obtenez toutes les valeurs annexables qui peuvent être mises en tableau.
     */
    protected function getArrayableAppends(): array
    {
        if (! count($this->appends)) {
            return [];
        }

        return $this->getArrayableItems(
            array_combine($this->appends, $this->appends)
        );
    }

    /**
     * Obtenez les relations du modèle sous forme de tableau.
     */
    public function relationsToArray(): array
    {
        $attributes = [];

        foreach ($this->getArrayableRelations() as $key => $value) {
            // Si les valeurs implémentent l'interface Arrayable, nous pouvons simplement appeler cette méthode
            // toArray sur les instances qui convertiront les modèles et les
            // collections dans leur forme de tableau appropriée et nous définirons les valeurs.
            if ($value instanceof Arrayable) {
                $relation = $value->toArray();
            }

            // Si la valeur est null, nous allons continuer et la définir dans cette liste
            // d'attributs, car null est utilisé pour représenter des relations vides si
            // elle en a une ou appartient à des relations de type sur les modèles.
            elseif (null === $value) {
                $relation = $value;
            }

            // Si les relations snake-casing sont activées,
            // nous mettrons cette clé en casse serpent afin que l'attribut de relation soit en casse serpent dans ce tableau renvoyé aux développeurs,
            // ce qui le rendra cohérent avec les attributs.
            if (static::$snakeAttributes) {
                $key = Text::snake($key);
            }

            // Si la valeur de la relation a été définie, nous la définirons sur cette liste d'attributs pour le retour.
            // S'il n'était pas tableau ou nul, nous ne définirons pas la valeur sur le tableau car il s'agit d'un type de valeur invalide.
            if (isset($relation) || null === $value) {
                $attributes[$key] = $relation;
            }

            unset($relation);
        }

        return $attributes;
    }

    /**
     * Obtenez un tableau d'attributs de toutes les relations pouvant être mises en tableau (arrayable).
     */
    protected function getArrayableRelations(): array
    {
        return $this->getArrayableItems($this->relations);
    }

    /**
     * Obtenez un tableau d'attributs de toutes les valeurs pouvant être mises en tableau.
     */
    protected function getArrayableItems(array $values): array
    {
        if (count($this->getVisible()) > 0) {
            $values = array_intersect_key($values, array_flip($this->getVisible()));
        }

        if (count($this->getHidden()) > 0) {
            $values = array_diff_key($values, array_flip($this->getHidden()));
        }

        return $values;
    }

    /**
     * Obtenez un attribut du modèle.
     */
    public function getAttribute(string $key): mixed
    {
        if (! $key) {
            return null;
        }

        // Si l'attribut existe dans le tableau d'attributs ou a un mutateur "get", nous obtiendrons la valeur de l'attribut.
        // Sinon, nous procéderons comme si les développeurs demandaient la valeur d'une relation.
        // Cela couvre les deux types de valeurs.
        if (
            array_key_exists($key, $this->attributes)
            || array_key_exists($key, $this->casts)
            || $this->hasGetMutator($key)
            || $this->hasAttributeMutator($key)
            || $this->isClassCastable($key)
        ) {
            return $this->getAttributeValue($key);
        }

        // Ici, nous déterminerons si la classe de base du modèle elle-même contient cette clé donnée,
        // car nous ne voulons traiter aucune de ces méthodes comme des relations,
        // car elles sont toutes conçues comme des méthodes d'assistance et aucune d'entre elles n'est une relation.
        if (method_exists(self::class, $key)) {
            return $this->throwMissingAttributeExceptionIfApplicable($key);
        }

        return $this->isRelation($key) || $this->relationLoaded($key)
            ? $this->getRelationValue($key)
            : $this->throwMissingAttributeExceptionIfApplicable($key);
    }

    /**
     * Either throw a missing attribute exception or return null depending on Eloquent's configuration.
     *
     * @return null
     *
     * @throws MissingAttributeException
     */
    protected function throwMissingAttributeExceptionIfApplicable(string $key)
    {
        if ($this->exists
            && ! $this->wasRecentlyCreated
            && static::preventsAccessingMissingAttributes()) {
            if (isset(static::$missingAttributeViolationCallback)) {
                return call_user_func(static::$missingAttributeViolationCallback, $this, $key);
            }

            throw new MissingAttributeException($this, $key);
        }

        return null;
    }

    /**
     * Obtenez un attribut simple (pas une relation).
     */
    public function getAttributeValue(string $key): mixed
    {
        return $this->transformModelValue($key, $this->getAttributeFromArray($key));
    }

    /**
     * Récupère un attribut du tableau $attributes.
     */
    protected function getAttributeFromArray(string $key): mixed
    {
        return $this->getAttributes()[$key] ?? null;
    }

    /**
     * Obtenez une relation.
     */
    public function getRelationValue(string $key): mixed
    {
        // Si la clé existe déjà dans le tableau des relations,
        // cela signifie simplement que la relation a déjà été chargée,
        // nous allons donc simplement la renvoyer d'ici car il n'est pas nécessaire d'interroger deux fois dans les relations.
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        if (! $this->isRelation($key)) {
            return null;
        }

        if ($this->preventsLazyLoading) {
            $this->handleLazyLoadingViolation($key);
        }

        // Si "l'attribut" existe en tant que méthode sur le modèle,
        // nous supposerons simplement qu'il s'agit d'une relation et chargerons et renverrons les résultats de la requête
        // et hydraterons la valeur de la relation sur le tableau "relations".
        return $this->getRelationshipFromMethod($key);
    }

    /**
     * Déterminez si la clé donnée est une méthode de relation sur le modèle.
     */
    public function isRelation(string $key): bool
    {
        if ($this->hasAttributeMutator($key)) {
            return false;
        }

        return method_exists($this, $key)
               || $this->relationResolver(static::class, $key);
    }

    /**
     * Handle a lazy loading violation.
     */
    protected function handleLazyLoadingViolation(string $key): mixed
    {
        if (isset(static::$lazyLoadingViolationCallback)) {
            return call_user_func(static::$lazyLoadingViolationCallback, $this, $key);
        }

        if (! $this->exists || $this->wasRecentlyCreated) {
            return null;
        }

        throw new LazyLoadingViolationException($this, $key);
    }

    /**
     * Obtenir une valeur de relation à partir d'une méthode.
     *
     * @throws LogicException
     */
    protected function getRelationshipFromMethod(string $method): mixed
    {
        $relation = $this->{$method}();

        if (! $relation instanceof Relation) {
            if (null === $relation) {
                throw new LogicException(sprintf(
                    "%s::%s doit renvoyer une instance de relation, mais \"null\" a été renvoyé. Le mot clé \"return\" a-t-il été utilisé\u{a0}?",
                    static::class,
                    $method
                ));
            }

            throw new LogicException(sprintf(
                '%s::%s doit renvoyer une instance de relation.',
                static::class,
                $method
            ));
        }

        return Helpers::tap($relation->getResults(), function ($results) use ($method) {
            $this->setRelation($method, $results);
        });
    }

    /**
     * Déterminez si un mutateur get existe pour un attribut.
     */
    public function hasGetMutator(string $key): bool
    {
        return method_exists($this, 'get' . Text::studly($key) . 'Attribute');
    }

    /**
     * Détermine si un mutateur marqué de type de retour "Attribute" existe pour un attribut.
     */
    public function hasAttributeMutator(string $key): bool
    {
        if (isset(static::$attributeMutatorCache[static::class][$key])) {
            return static::$attributeMutatorCache[static::class][$key];
        }

        if (! method_exists($this, $method = Text::camel($key))) {
            return static::$attributeMutatorCache[static::class][$key] = false;
        }

        $returnType = (new ReflectionMethod($this, $method))->getReturnType();

        return static::$attributeMutatorCache[static::class][$key] = $returnType instanceof ReflectionNamedType
                    && $returnType->getName() === Attribute::class;
    }

    /**
     * Déterminez si un type de retour « Attribut » marqué get mutator existe pour un attribut.
     */
    public function hasAttributeGetMutator(string $key): bool
    {
        if (isset(static::$getAttributeMutatorCache[static::class][$key])) {
            return static::$getAttributeMutatorCache[static::class][$key];
        }

        if (! $this->hasAttributeMutator($key)) {
            return static::$getAttributeMutatorCache[static::class][$key] = false;
        }

        return static::$getAttributeMutatorCache[static::class][$key] = is_callable($this->{Text::camel($key)}()->get);
    }

    /**
     * Obtenir la valeur d'un attribut à l'aide de son mutateur.
     */
    protected function mutateAttribute(string $key, mixed $value): mixed
    {
        return $this->{'get' . Text::studly($key) . 'Attribute'}($value);
    }

    /**
     * Récupère la valeur d'un attribut marqué de type de retour "Attribute" à l'aide de son mutateur.
     */
    protected function mutateAttributeMarkedAttribute(string $key, mixed $value): mixed
    {
        if (array_key_exists($key, $this->attributeCastCache)) {
            return $this->attributeCastCache[$key];
        }

        $attribute = $this->{Text::camel($key)}();

        $value = ($attribute->get ?: fn ($value) => $value)($value, $this->attributes);

        if ($attribute->withCaching || (is_object($value) && $attribute->withObjectCaching)) {
            $this->attributeCastCache[$key] = $value;
        } else {
            unset($this->attributeCastCache[$key]);
        }

        return $value;
    }

    /**
     * Obtenez la valeur d'un attribut à l'aide de son mutateur pour la conversion de tableau.
     */
    protected function mutateAttributeForArray(string $key, mixed $value): mixed
    {
        if ($this->isClassCastable($key)) {
            $value = $this->getClassCastableAttributeValue($key, $value);
        } elseif (isset(static::$getAttributeMutatorCache[static::class][$key]) && static::$getAttributeMutatorCache[static::class][$key] === true) {
            $value = $this->mutateAttributeMarkedAttribute($key, $value);

            $value = $value instanceof DateTimeInterface
                        ? $this->serializeDate($value)
                        : $value;
        } else {
            $value = $this->mutateAttribute($key, $value);
        }

        return $value instanceof Arrayable ? $value->toArray() : $value;
    }

    /**
     * Fusionnez les nouveaux casts avec les casts existants sur le modèle.
     */
    public function mergeCasts(array $casts): self
    {
        $this->casts = array_merge($this->casts, $casts);

        return $this;
    }

    /**
     * Attribuez un attribut à un type PHP natif.
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        $castType = $this->getCastType($key);

        if (null === $value && in_array($castType, static::$primitiveCastTypes, true)) {
            return $value;
        }

        // Si la clé est l'un des types de castable crypté, nous allons d'abord déchiffrer la valeur et
        // mettre à jour le type de cast afin que nous pouvons exploiter la logique suivante pour
        // le casting de cette valeur à tous les types spécifiés en plus.
        if ($this->isEncryptedCastable($key)) {
            $value = $this->fromEncryptedString($value);

            $castType = Text::after($castType, 'encrypted:');
        }

        if ($castType[0] === '?') {
            if ($value === null) {
                return null;
            }
            $castType = substr($castType, 1);
        }

        switch ($castType) {
            case 'int':
            case 'integer':
                return (int) $value;

            case 'real':
            case 'float':
            case 'double':
                return $this->fromFloat($value);

            case 'decimal':
                return $this->asDecimal($value, explode(':', $this->getCasts()[$key], 2)[1]);

            case 'string':
                return (string) $value;

            case 'bool':
            case 'boolean':
                return (bool) $value;

            case 'object':
                return $this->fromJson($value, true);

            case 'array':
            case 'json':
                return $this->fromJson($value);

            case 'collection':
                return new IterableCollection($this->fromJson($value));

            case 'date':
                return $this->asDate($value);

            case 'datetime':
            case 'custom_datetime':
                return $this->asDateTime($value);

            case 'timestamp':
                return $this->asTimestamp($value);
        }

        if ($this->isEnumCastable($key)) {
            return $this->getEnumCastableAttributeValue($key, $value);
        }

        if ($this->isClassCastable($key)) {
            return $this->getClassCastableAttributeValue($key, $value);
        }

        return $value;
    }

    /**
     * Cast l'attribut donné à l'aide d'une classe de Cast personnalisée.
     */
    protected function getClassCastableAttributeValue(string $key, mixed $value): mixed
    {
        $caster = $this->resolveCasterClass($key);

        $objectCachingDisabled = $caster->withoutObjectCaching ?? false;

        if (isset($this->classCastCache[$key]) && ! $objectCachingDisabled) {
            return $this->classCastCache[$key];
        }

        $value = $caster instanceof CastsInboundAttributes
                    ? $value
                    : $caster->get($this, $key, $value, $this->attributes);

        if ($caster instanceof CastsInboundAttributes || ! is_object($value) || $objectCachingDisabled) {
            unset($this->classCastCache[$key]);
        } else {
            $this->classCastCache[$key] = $value;
        }

        return $value;
    }

    /**
     * Castez l'attribut donné en énumération.
     */
    protected function getEnumCastableAttributeValue(string $key, mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        $castType = $this->getCasts()[$key];

        if ($value instanceof $castType) {
            return $value;
        }

        return $this->getEnumCaseFromValue($castType, $value);
    }

    /**
     * Obtenez le type de cast pour un attribut de modèle.
     */
    protected function getCastType(string $key): string
    {
        $castType = $this->getCasts()[$key];

        if (isset(static::$castTypeCache[$castType])) {
            return static::$castTypeCache[$castType];
        }

        if ($this->isCustomDateTimeCast($castType)) {
            $convertedCastType = 'custom_datetime';
        } elseif ($this->isDecimalCast($castType)) {
            $convertedCastType = 'decimal';
        } elseif (class_exists($castType)) {
            $convertedCastType = $castType;
        } else {
            $convertedCastType = trim(strtolower($castType));
        }

        return static::$castTypeCache[$castType] = $convertedCastType;
    }

    /**
     * Incrémente ou décrémente l'attribut donné à l'aide de la classe de distribution personnalisée.
     */
    protected function deviateClassCastableAttribute(string $method, string $key, mixed $value): mixed
    {
        return $this->resolveCasterClass($key)->{$method}(
            $this,
            $key,
            $value,
            $this->attributes
        );
    }

    /**
     * Sérialisez l'attribut donné à l'aide de la classe de distribution personnalisée.
     */
    protected function serializeClassCastableAttribute(string $key, mixed $value): mixed
    {
        return $this->resolveCasterClass($key)->serialize(
            $this,
            $key,
            $value,
            $this->attributes
        );
    }

    /**
     * Déterminez si le type de distribution est une distribution date/heure personnalisée.
     */
    protected function isCustomDateTimeCast(string $cast): bool
    {
        return str_starts_with($cast, 'date:')
                || str_starts_with($cast, 'datetime:');
    }

    /**
     * Déterminez si le type de cast est un cast décimal.
     */
    protected function isDecimalCast(string $cast): bool
    {
        return str_starts_with($cast, 'decimal:');
    }

    /**
     * Définissez un attribut donné sur le modèle.
     */
    public function setAttribute(string $key, mixed $value): mixed
    {
        // Nous allons d'abord vérifier la présence d'un mutateur pour l'opération d'ensemble qui
        // permet simplement aux développeurs de modifier l'attribut tel qu'il est défini sur
        // ce modèle, comme "json_encoding" une liste de données pour le stockage.
        if ($this->hasSetMutator($key)) {
            return $this->setMutatedAttributeValue($key, $value);
        }
        if ($this->hasAttributeSetMutator($key)) {
            return $this->setAttributeMarkedMutatedAttributeValue($key, $value);
        }

        // Si un attribut est répertorié comme une "date", nous le convertirons d'une
        // instance DateTime en une forme appropriée pour le stockage sur les tables de la base de données en utilisant le format de date de la grammaire de connexion.
        // Nous définirons automatiquement les valeurs.
        if (null !== $value && $this->isDateAttribute($key)) {
            $value = $this->fromDateTime($value);
        }

        if ($this->isEnumCastable($key)) {
            $this->setEnumCastableAttribute($key, $value);

            return $this;
        }

        if ($this->isClassCastable($key)) {
            $this->setClassCastableAttribute($key, $value);

            return $this;
        }

        if (null !== $value && $this->isJsonCastable($key)) {
            $value = $this->castAttributeAsJson($key, $value);
        }

        // Si cet attribut contient un JSON ->, nous définirons la valeur appropriée dans le tableau sous-jacent de l'attribut.
        // Cela permet d'imbriquer correctement un attribut dans la valeur du tableau dans le cas d'éléments profondément imbriqués.
        if (str_contains($key, '->')) {
            return $this->fillJsonAttribute($key, $value);
        }

        if (null !== $value && $this->isEncryptedCastable($key)) {
            $value = $this->castAttributeAsEncryptedString($key, $value);
        }

        if (null !== $value && $this->hasCast($key, 'hashed')) {
            $value = $this->castAttributeAsHashedString($key, $value);
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Détermine si un setter existe pour un attribut.
     */
    public function hasSetMutator(string $key): bool
    {
        return method_exists($this, 'set' . Text::studly($key) . 'Attribute');
    }

    /**
     * Déterminez si un mutateur d'ensemble marqué de type de retour "Attribut" existe pour un attribut.
     */
    public function hasAttributeSetMutator(string $key): bool
    {
        if (isset(static::$setAttributeMutatorCache[static::class][$key])) {
            return static::$setAttributeMutatorCache[static::class][$key];
        }

        if (! method_exists($this, $method = Text::camel($key))) {
            return static::$setAttributeMutatorCache[static::class][$key] = false;
        }

        $returnType = (new ReflectionMethod($this, $method))->getReturnType();

        return static::$setAttributeMutatorCache[static::class][$key] = $returnType instanceof ReflectionNamedType
                    && $returnType->getName() === Attribute::class
                    && is_callable($this->{$method}()->set);
    }

    /**
     * Définissez la valeur d'un attribut à l'aide de son mutateur.
     */
    protected function setMutatedAttributeValue(string $key, mixed $value): mixed
    {
        return $this->{'set' . Text::studly($key) . 'Attribute'}($value);
    }

    /**
     * Définissez la valeur d'un attribut marqué de type de retour "Attribute" à l'aide de son mutateur.
     */
    protected function setAttributeMarkedMutatedAttributeValue(string $key, mixed $value): mixed
    {
        $attribute = $this->{Text::camel($key)}();

        $callback = $attribute->set ?: function ($value) use ($key) {
            $this->attributes[$key] = $value;
        };

        $this->attributes = array_merge(
            $this->attributes,
            $this->normalizeCastClassResponse(
                $key,
                $callback($value, $this->attributes)
            )
        );

        if ($attribute->withCaching || (is_object($value) && $attribute->withObjectCaching)) {
            $this->attributeCastCache[$key] = $value;
        } else {
            unset($this->attributeCastCache[$key]);
        }

        return $this;
    }

    /**
     * Déterminez si l'attribut donné est une date ou une date castable.
     */
    protected function isDateAttribute(string $key): bool
    {
        return in_array($key, $this->getDates(), true)
               || $this->isDateCastable($key);
    }

    /**
     * Définissez un attribut JSON donné sur le modèle.
     */
    public function fillJsonAttribute(string $key, mixed $value): self
    {
        [$key, $path] = explode('->', $key, 2);

        $value = $this->asJson($this->getArrayAttributeWithValue(
            $path,
            $key,
            $value
        ));

        $this->attributes[$key] = $this->isEncryptedCastable($key)
                    ? $this->castAttributeAsEncryptedString($key, $value)
                    : $value;

        if ($this->isClassCastable($key)) {
            unset($this->classCastCache[$key]);
        }

        return $this;
    }

    /**
     * Définissez la valeur d'un attribut castable de classe.
     */
    protected function setClassCastableAttribute(string $key, mixed $value): void
    {
        $caster = $this->resolveCasterClass($key);

        $this->attributes = array_replace(
            $this->attributes,
            $this->normalizeCastClassResponse($key, $caster->set(
                $this,
                $key,
                $value,
                $this->attributes
            ))
        );

        if ($caster instanceof CastsInboundAttributes || ! is_object($value) || ($caster->withoutObjectCaching ?? false)) {
            unset($this->classCastCache[$key]);
        } else {
            $this->classCastCache[$key] = $value;
        }
    }

    /**
     * Définissez la valeur d'un attribut castable enum.
     *
     * @param int|string|UnitEnum $value
     */
    protected function setEnumCastableAttribute(string $key, $value): void
    {
        $enumClass = $this->getCasts()[$key];

        if (! isset($value)) {
            $this->attributes[$key] = null;
        } elseif (is_object($value)) {
            $this->attributes[$key] = $this->getStorableEnumValue($value);
        } else {
            $this->attributes[$key] = $this->getStorableEnumValue(
                $this->getEnumCaseFromValue($enumClass, $value)
            );
        }
    }

    /**
     * Obtenez une instance de cas enum à partir d'une classe et d'une valeur données.
     *
     * @param int|string $value
     *
     * @return BackedEnum|UnitEnum
     */
    protected function getEnumCaseFromValue(string $enumClass, $value)
    {
        return is_subclass_of($enumClass, BackedEnum::class)
            ? $enumClass::from($value)
            : constant($enumClass . '::' . $value);
    }

    /**
     * Obtenez la valeur stockable à partir de l'énumération donnée.
     *
     * @param BackedEnum|UnitEnum $value
     *
     * @return int|string
     */
    protected function getStorableEnumValue($value)
    {
        return $value instanceof BackedEnum
                ? $value->value
                : $value->name;
    }

    /**
     * Obtenez un attribut de tableau avec la clé et la valeur définies.
     */
    protected function getArrayAttributeWithValue(string $path, string $key, mixed $value): self
    {
        return Helpers::tap($this->getArrayAttributeByKey($key), static function (&$array) use ($path, $value) {
            Arr::set($array, str_replace('->', '.', $path), $value);
        });
    }

    /**
     * Récupère un attribut de tableau ou renvoie un tableau vide s'il n'est pas défini.
     */
    protected function getArrayAttributeByKey(string $key): array
    {
        if (! isset($this->attributes[$key])) {
            return [];
        }

        return $this->fromJson(
            $this->isEncryptedCastable($key)
                ? $this->fromEncryptedString($this->attributes[$key])
                : $this->attributes[$key]
        );
    }

    /**
     * Convertissez l'attribut donné en JSON.
     */
    protected function castAttributeAsJson(string $key, mixed $value): string
    {
        $value = $this->asJson($value);

        if ($value === false) {
            throw JsonEncodingException::forAttribute(
                $this,
                $key,
                json_last_error_msg()
            );
        }

        return $value;
    }

    /**
     * Encodez la valeur donnée au format JSON.
     */
    protected function asJson(mixed $value): string
    {
        return Json::encode($value);
    }

    /**
     * Décodez le JSON donné dans un tableau ou un objet.
     */
    public function fromJson(string $value, bool $asObject = false): mixed
    {
        return Json::decode($value ?? '', ! $asObject);
    }

    /**
     * Déchiffrer la chaîne chiffrée donnée.
     */
    public function fromEncryptedString(string $value): mixed
    {
        return static::$encrypter->decrypt($value, false);
    }

    /**
     * Castez l'attribut donné en une chaîne chiffrée.
     */
    protected function castAttributeAsEncryptedString(string $key, mixed $value): string
    {
        return static::$encrypter->encrypt($value, false);
    }

    /**
     * Définissez l'instance de chiffrement qui sera utilisée pour chiffrer les attributs.
     */
    public static function encryptUsing(?EncrypterInterface $encrypter): void
    {
        static::$encrypter = $encrypter;
    }

    /**
     * Castez l'attribut donné en une chaîne hachée.
     */
    protected function castAttributeAsHashedString(string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        /*
        if (! Hash::isHashed($value)) {
            return Hash::make($value);
        }

        if (! Hash::verifyConfiguration($value)) {
            throw new RuntimeException("Could not verify the hashed value's configuration.");
        }
        */

        return (string) $value;
    }

    /**
     * Décode le float donné.
     */
    public function fromFloat(mixed $value): mixed
    {
        return match ((string) $value) {
            'Infinity'  => INF,
            '-Infinity' => -INF,
            'NaN'       => NAN,
            default     => (float) $value
        };
    }

    /**
     * Renvoie un nombre décimal sous forme de chaîne.
     */
    protected function asDecimal(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', '');
    }

    /**
     * Renvoie un horodatage en tant qu'objet DateTime avec l'heure définie sur 00:00:00
     */
    protected function asDate(mixed $value): Date
    {
        return $this->asDateTime($value)->startOfDay();
    }

    /**
     * Renvoie un horodatage en tant qu'objet DateTime.
     */
    protected function asDateTime(mixed $value): Date
    {
        // Si cette valeur est déjà une instance Date, nous la renverrons simplement telle quelle.
        // Cela nous évite d'avoir à ré-instancier une instance Date alors que nous savons qu'elle en est déjà une,
        // ce qui ne serait pas remplie par la vérification DateTime.
        if ($value instanceof Date) {
            return Date::createFromInstance($value);
        }

        // Si la valeur est déjà une instance DateTime, nous allons simplement ignorer le reste de ces vérifications car
        // elles seront une perte de temps et entraveront les performances lors de la vérification du champ.
        // Nous allons simplement retourner le DateTime tout de suite.
        if ($value instanceof DateTimeInterface) {
            return Date::parse(
                $value->format('Y-m-d H:i:s.u'),
                $value->getTimezone()
            );
        }

        // If this value is an integer, we will assume it is a UNIX timestamp's value and format a Date object from this timestamp.
        // This allows flexibility when defining your date fields as they might be UNIX timestamps here.
        if (is_numeric($value)) {
            return Date::createFromTimestamp($value);
        }

        // Si la valeur est simplement au format année, mois, jour, nous instancions les instances Date à partir de ce format.
        // Encore une fois, cela fournit des champs de date simples sur la base de données, tout en prenant en charge la conversion en date Blitz.
        if ($this->isStandardDateFormat($value)) {
            return Date::createFrominstance(Date::createFromFormat('Y-m-d', $value)->startOfDay());
        }

        $format = $this->getDateFormat();

        // Enfin, nous supposerons simplement que cette date est dans le format utilisé par défaut sur
        // la connexion à la base de données et utiliserons ce format pour créer l'objet Date qui est
        // renvoyé aux développeurs après l'avoir converti ici.
        try {
            $date = Date::createFromFormat($format, $value);
        } catch (InvalidArgumentException $e) {
            $date = false;
        }

        return $date ?: Date::parse($value);
    }

    /**
     * Déterminez si la valeur donnée est un format de date standard.
     */
    protected function isStandardDateFormat(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value);
    }

    /**
     * Convertir un DateTime en une chaîne stockable.
     */
    public function fromDateTime(mixed $value): ?string
    {
        return empty($value) ? $value : $this->asDateTime($value)->format(
            $this->getDateFormat()
        );
    }

    /**
     * Return a timestamp as unix timestamp.
     */
    protected function asTimestamp(mixed $value): int
    {
        return $this->asDateTime($value)->getTimestamp();
    }

    /**
     * Préparez une date pour la sérialisation du tableau/JSON.
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return Date::createFromInstance($date)->format($this->getDateFormat());
    }

    /**
     * Obtenez les attributs qui doivent être convertis en dates.
     */
    public function getDates(): array
    {
        if (! $this->usesTimestamps()) {
            return $this->dates ?? [];
        }

        $defaults = [
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
        ];

        return array_unique(array_merge($this->dates ?? [], $defaults));
    }

    /**
     * Get the format for database stored dates.
     */
    public function getDateFormat(): string
    {
        return $this->dateFormat ?: 'Y-m-d H:i:s';
    }

    /**
     * Set the date format used by the model.
     */
    public function setDateFormat(string $format): self
    {
        $this->dateFormat = $format;

        return $this;
    }

    /**
     * Determine whether an attribute should be cast to a native type.
     */
    public function hasCast(string $key, null|array|string $types = null): bool
    {
        if (array_key_exists($key, $this->getCasts())) {
            return $types ? in_array($this->getCastType($key), (array) $types, true) : true;
        }

        return false;
    }

    /**
     * Get the casts array.
     */
    public function getCasts(): array
    {
        foreach ($this->casts as $key => $cast) {
            if (in_array($cast, ['boolean', 'bool'], true)) {
                $this->casts[$key] = AsIntBool::class;
            }
        }

        if ($this->getIncrementing()) {
            return array_merge([$this->getKeyName() => $this->getKeyType()], $this->casts);
        }

        return $this->casts;
    }

    /**
     * Determine whether a value is Date / DateTime castable for inbound manipulation.
     */
    protected function isDateCastable(string $key): bool
    {
        return $this->hasCast($key, ['date', 'datetime']);
    }

    /**
     * Determine whether a value is Date / DateTime custom-castable for inbound manipulation.
     */
    protected function isDateCastableWithCustomFormat(string $key): bool
    {
        return $this->hasCast($key, ['custom_datetime']);
    }

    /**
     * Determine whether a value is JSON castable for inbound manipulation.
     */
    protected function isJsonCastable(string $key): bool
    {
        return $this->hasCast($key, ['array', 'json', 'object', 'collection', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object']);
    }

    /**
     * Determine whether a value is an encrypted castable for inbound manipulation.
     */
    protected function isEncryptedCastable(string $key): bool
    {
        return $this->hasCast($key, ['encrypted', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object']);
    }

    /**
     * Determine if the given key is cast using a custom class.
     *
     * @throws InvalidCastException
     */
    protected function isClassCastable(string $key): bool
    {
        $casts = $this->getCasts();

        if (! array_key_exists($key, $casts)) {
            return false;
        }

        $castType = $this->parseCasterClass($casts[$key]);

        if (in_array($castType, static::$primitiveCastTypes, true)) {
            return false;
        }

        if (class_exists($castType)) {
            return true;
        }

        throw new InvalidCastException($this->getModel(), $key, $castType);
    }

    /**
     * Determine if the given key is cast using an enum.
     */
    protected function isEnumCastable(string $key): bool
    {
        $casts = $this->getCasts();

        if (! array_key_exists($key, $casts)) {
            return false;
        }

        $castType = $casts[$key];

        if (in_array($castType, static::$primitiveCastTypes, true)) {
            return false;
        }

        return enum_exists($castType);
    }

    /**
     * Determine if the key is deviable using a custom class.
     *
     * @throws InvalidCastException
     */
    protected function isClassDeviable(string $key): bool
    {
        if (! $this->isClassCastable($key)) {
            return false;
        }

        $castType = $this->resolveCasterClass($key);

        return method_exists($castType::class, 'increment') && method_exists($castType::class, 'decrement');
    }

    /**
     * Determine if the key is serializable using a custom class.
     *
     * @throws InvalidCastException
     */
    protected function isClassSerializable(string $key): bool
    {
        return ! $this->isEnumCastable($key)
            && $this->isClassCastable($key)
            && method_exists($this->resolveCasterClass($key), 'serialize');
    }

    /**
     * Resolve the custom caster class for a given key.
     */
    protected function resolveCasterClass(string $key): mixed
    {
        $castType = $this->getCasts()[$key];

        $arguments = [];

        if (is_string($castType) && str_contains($castType, ':')) {
            $segments = explode(':', $castType, 2);

            $castType  = $segments[0];
            $arguments = explode(',', $segments[1]);
        }

        if (is_subclass_of($castType, Castable::class)) {
            $castType = $castType::castUsing($arguments);
        }

        if (is_object($castType)) {
            return $castType;
        }

        return new $castType(...$arguments);
    }

    /**
     * Parse the given caster class, removing any arguments.
     */
    protected function parseCasterClass(string $class): string
    {
        $class = str_contains($class, ':')
            ? $class
            : explode(':', $class, 2)[0];

        if ($class[0] === '?') {
            $class = substr($class, 1);
        }

        return $class;
    }

    /**
     * Merge the cast class and attribute cast attributes back into the model.
     *
     * @return void
     */
    protected function mergeAttributesFromCachedCasts()
    {
        $this->mergeAttributesFromClassCasts();
        $this->mergeAttributesFromAttributeCasts();
    }

    /**
     * Merge the cast class attributes back into the model.
     */
    protected function mergeAttributesFromClassCasts(): void
    {
        foreach ($this->classCastCache as $key => $value) {
            $caster = $this->resolveCasterClass($key);

            $this->attributes = array_merge(
                $this->attributes,
                $caster instanceof CastsInboundAttributes
                       ? [$key => $value]
                       : $this->normalizeCastClassResponse($key, $caster->set($this, $key, $value, $this->attributes))
            );
        }
    }

    /**
     * Merge the cast class attributes back into the model.
     */
    protected function mergeAttributesFromAttributeCasts(): void
    {
        foreach ($this->attributeCastCache as $key => $value) {
            $attribute = $this->{Text::camel($key)}();

            if ($attribute->get && ! $attribute->set) {
                continue;
            }

            $callback = $attribute->set ?: function ($value) use ($key) {
                $this->attributes[$key] = $value;
            };

            $this->attributes = array_merge(
                $this->attributes,
                $this->normalizeCastClassResponse(
                    $key,
                    $callback($value, $this->attributes)
                )
            );
        }
    }

    /**
     * Normalize the response from a custom class caster.
     */
    protected function normalizeCastClassResponse(string $key, mixed $value): array
    {
        return is_array($value) ? $value : [$key => $value];
    }

    /**
     * Get all of the current attributes on the model.
     */
    public function getAttributes(): array
    {
        $this->mergeAttributesFromCachedCasts();

        return $this->attributes;
    }

    /**
     * Get all of the current attributes on the model for an insert operation.
     */
    protected function getAttributesForInsert(): array
    {
        return $this->getAttributes();
    }

    /**
     * Set the array of model attributes. No checking is done.
     */
    public function setRawAttributes(array $attributes, bool $sync = false): self
    {
        $this->attributes = $attributes;

        if ($sync) {
            $this->syncOriginal();
        }

        $this->classCastCache     = [];
        $this->attributeCastCache = [];

        return $this;
    }

    /**
     * Get the model's original attribute values.
     *
     * @return array|mixed
     */
    public function getOriginal(?string $key = null, mixed $default = null)
    {
        return (new static())->setRawAttributes(
            $this->original,
            $sync = true
        )->getOriginalWithoutRewindingModel($key, $default);
    }

    /**
     * Get the model's original attribute values.
     *
     * @return array|mixed
     */
    protected function getOriginalWithoutRewindingModel(?string $key = null, mixed $default = null)
    {
        if ($key) {
            return $this->transformModelValue(
                $key,
                Arr::get($this->original, $key, $default)
            );
        }

        return Helpers::collect($this->original)->mapWithKeys(fn ($value, $key) => [$key => $this->transformModelValue($key, $value)])->all();
    }

    /**
     * Get the model's raw original attribute values.
     *
     * @return array|mixed
     */
    public function getRawOriginal(?string $key = null, mixed $default = null)
    {
        return Arr::get($this->original, $key, $default);
    }

    /**
     * Get a subset of the model's attributes.
     *
     * @param  array|...string  $attributes
     */
    public function only($attributes): array
    {
        $results = [];

        foreach (is_array($attributes) ? $attributes : func_get_args() as $attribute) {
            $results[$attribute] = $this->getAttribute($attribute);
        }

        return $results;
    }

    /**
     * Sync the original attributes with the current.
     */
    public function syncOriginal(): self
    {
        $this->original = $this->getAttributes();

        return $this;
    }

    /**
     * Sync a single original attribute with its current value.
     */
    public function syncOriginalAttribute(string $attribute): self
    {
        return $this->syncOriginalAttributes($attribute);
    }

    /**
     * Sync multiple original attribute with their current values.
     *
     * @param  array|...string $attributes
     */
    public function syncOriginalAttributes($attributes): self
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $modelAttributes = $this->getAttributes();

        foreach ($attributes as $attribute) {
            $this->original[$attribute] = $modelAttributes[$attribute];
        }

        return $this;
    }

    /**
     * Sync the changed attributes.
     */
    public function syncChanges(): self
    {
        $this->changes = $this->getDirty();

        return $this;
    }

    /**
     * Determine if the model or any of the given attribute(s) have been modified.
     *
     * @param  array|...string|null  $attributes
     */
    public function isDirty($attributes = null): bool
    {
        return $this->hasChanges(
            $this->getDirty(),
            is_array($attributes) ? $attributes : func_get_args()
        );
    }

    /**
     * Determine if the model and all the given attribute(s) have remained the same.
     *
     * @param  array|...string|null  $attributes
     */
    public function isClean($attributes = null): bool
    {
        return ! $this->isDirty(...func_get_args());
    }

    /**
     * Discard attribute changes and reset the attributes to their original state.
     */
    public function discardChanges(): self
    {
        [$this->attributes, $this->changes] = [$this->original, []];

        return $this;
    }

    /**
     * Determine if the model or any of the given attribute(s) have been modified.
     *
     * @param  array|...string|null  $attributes
     */
    public function wasChanged($attributes = null): bool
    {
        return $this->hasChanges(
            $this->getChanges(),
            is_array($attributes) ? $attributes : func_get_args()
        );
    }

    /**
     * Determine if any of the given attributes were changed.
     */
    protected function hasChanges(array $changes, null|array|string $attributes = null): bool
    {
        // If no specific attributes were provided, we will just see if the dirty array
        // already contains any attributes. If it does we will just return that this
        // count is greater than zero. Else, we need to check specific attributes.
        if (empty($attributes)) {
            return count($changes) > 0;
        }

        // Here we will spin through every attribute and see if this is in the array of
        // dirty attributes. If it is, we will return true and if we make it through
        // all of the attributes for the entire array we will return false at end.
        foreach (Arr::wrap($attributes) as $attribute) {
            if (array_key_exists($attribute, $changes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the attributes that have been changed since the last sync.
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->getAttributes() as $key => $value) {
            if (! $this->originalIsEquivalent($key)) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * Get the attributes that have been changed since the last sync for an update operation.
     */
    protected function getDirtyForUpdate(): array
    {
        return $this->getDirty();
    }

    /**
     * Get the attributes that were changed.
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * Determine if the new and old values for a given key are equivalent.
     */
    public function originalIsEquivalent(string $key): bool
    {
        if (! array_key_exists($key, $this->original)) {
            return false;
        }

        $attribute = Arr::get($this->attributes, $key);
        $original  = Arr::get($this->original, $key);

        if ($attribute === $original) {
            return true;
        }
        if (null === $attribute) {
            return false;
        }
        if ($this->isDateAttribute($key) || $this->isDateCastableWithCustomFormat($key)) {
            return $this->fromDateTime($attribute) ===
                   $this->fromDateTime($original);
        }
        if ($this->hasCast($key, ['object', 'collection'])) {
            return $this->fromJson($attribute) === $this->fromJson($original);
        }
        if ($this->hasCast($key, ['real', 'float', 'double'])) {
            if (($attribute === null && $original !== null) || ($attribute !== null && $original === null)) {
                return false;
            }

            return abs($this->castAttribute($key, $attribute) - $this->castAttribute($key, $original)) < PHP_FLOAT_EPSILON * 4;
        }
        if ($this->hasCast($key, static::$primitiveCastTypes)) {
            return $this->castAttribute($key, $attribute) ===
                   $this->castAttribute($key, $original);
        }
        if ($this->isClassCastable($key) && Text::startsWith($this->getCasts()[$key], [AsArrayObject::class, AsCollection::class])) {
            return $this->fromJson($attribute) === $this->fromJson($original);
        }
        if ($this->isClassCastable($key) && Text::startsWith($this->getCasts()[$key], [AsEnumArrayObject::class, AsEnumCollection::class])) {
            return $this->fromJson($attribute) === $this->fromJson($original);
        }
        if ($this->isClassCastable($key) && $original !== null && Text::startsWith($this->getCasts()[$key], [AsEncryptedArrayObject::class, AsEncryptedCollection::class])) {
            return $this->fromEncryptedString($attribute) === $this->fromEncryptedString($original);
        }

        return is_numeric($attribute) && is_numeric($original)
               && strcmp((string) $attribute, (string) $original) === 0;
    }

    /**
     * Transform a raw model value using mutators, casts, etc.
     */
    protected function transformModelValue(string $key, mixed $value): mixed
    {
        // If the attribute has a get mutator, we will call that then return what
        // it returns as the value, which is useful for transforming values on
        // retrieval from the model to a form that is more useful for usage.
        if ($this->hasGetMutator($key)) {
            return $this->mutateAttribute($key, $value);
        }
        if ($this->hasAttributeGetMutator($key)) {
            return $this->mutateAttributeMarkedAttribute($key, $value);
        }

        // If the attribute exists within the cast array, we will convert it to
        // an appropriate native PHP type dependent upon the associated value
        // given with the key in the pair. Dayle made this comment line up.
        if ($this->hasCast($key)) {
            if (static::preventsAccessingMissingAttributes()
                && ! array_key_exists($key, $this->attributes)
                && ($this->isEnumCastable($key)
                 || in_array($this->getCastType($key), static::$primitiveCastTypes, true))) {
                $this->throwMissingAttributeExceptionIfApplicable($key);
            }

            return $this->castAttribute($key, $value);
        }

        // If the attribute is listed as a date, we will convert it to a DateTime
        // instance on retrieval, which makes it quite convenient to work with
        // date fields without having to create a mutator for each property.
        if ($value !== null && in_array($key, $this->getDates(), false)) {
            return $this->asDateTime($value);
        }

        return $value;
    }

    /**
     * Append attributes to query when building a query.
     *
     * @param  array|...string  $attributes
     */
    public function append($attributes): self
    {
        $this->appends = array_values(array_unique(
            array_merge($this->appends, is_string($attributes) ? func_get_args() : $attributes)
        ));

        return $this;
    }

    /**
     * Get the accessors that are being appended to model arrays.
     */
    public function getAppends(): array
    {
        return $this->appends;
    }

    /**
     * Set the accessors to append to model arrays.
     */
    public function setAppends(array $appends): self
    {
        $this->appends = $appends;

        return $this;
    }

    /**
     * Return whether the accessor attribute has been appended.
     */
    public function hasAppended(string $attribute): bool
    {
        return in_array($attribute, $this->appends, true);
    }

    /**
     * Get the mutated attributes for a given instance.
     */
    public function getMutatedAttributes(): array
    {
        if (! isset(static::$mutatorCache[static::class])) {
            static::cacheMutatedAttributes($this);
        }

        return static::$mutatorCache[static::class];
    }

    /**
     * Extract and cache all the mutated attributes of a class.
     */
    public static function cacheMutatedAttributes(object|string $classOrInstance): void
    {
        $reflection = new ReflectionClass($classOrInstance);

        $class = $reflection->getName();

        static::$getAttributeMutatorCache[$class] = Helpers::collect($attributeMutatorMethods = static::getAttributeMarkedMutatorMethods($classOrInstance))
            ->mapWithKeys(static fn ($match) => [lcfirst(static::$snakeAttributes ? Text::snake($match) : $match) => true])->all();

        static::$mutatorCache[$class] = Helpers::collect(static::getMutatorMethods($class))
            ->merge($attributeMutatorMethods)
            ->map(static fn ($match) => lcfirst(static::$snakeAttributes ? Text::snake($match) : $match))->all();
    }

    /**
     * Get all of the attribute mutator methods.
     */
    protected static function getMutatorMethods(mixed $class): array
    {
        preg_match_all('/(?<=^|;)get([^;]+?)Attribute(;|$)/', implode(';', get_class_methods($class)), $matches);

        return $matches[1];
    }

    /**
     * Get all of the "Attribute" return typed attribute mutator methods.
     */
    protected static function getAttributeMarkedMutatorMethods(object|string $class): array
    {
        $instance = is_object($class) ? $class : new $class();

        return Helpers::collect((new ReflectionClass($instance))->getMethods())->filter(static function ($method) use ($instance) {
            $returnType = $method->getReturnType();

            if ($returnType instanceof ReflectionNamedType
                && $returnType->getName() === Attribute::class) {
                if (is_callable($method->invoke($instance)->get)) {
                    return true;
                }
            }

            return false;
        })->map->name->values()->all();
    }
}
