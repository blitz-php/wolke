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

use BlitzPHP\Contracts\Support\Arrayable;
use BlitzPHP\Utilities\Date;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Contracts\Castable;
use BlitzPHP\Wolke\Contracts\CastsInboundAttributes;
use BlitzPHP\Wolke\Exceptions\InvalidCastException;
use BlitzPHP\Wolke\Exceptions\JsonEncodingException;
use BlitzPHP\Wolke\Relations\Relation;
use DateTimeInterface;
use InvalidArgumentException;
use LogicException;

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
     * The encrypter instance that is used to encrypt attributes.
     *
     * @var \CodeIgniter\Encryption\EncrypterInterface
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
     * Add the date attributes to the attributes array.
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
     * Add the mutated attributes to the attributes array.
     */
    protected function addMutatedAttributesToArray(array $attributes, array $mutatedAttributes): array
    {
        foreach ($mutatedAttributes as $key) {
            // We want to spin through all the mutated attributes for this model and call
            // the mutator for the attribute. We cache off every mutated attributes so
            // we don't have to constantly check on attributes that actually change.
            if (! array_key_exists($key, $attributes)) {
                continue;
            }

            // Next, we will call the mutator for this attribute so that we can get these
            // mutated attribute's actual values. After we finish mutating each of the
            // attributes we will return this final array of the mutated attributes.
            $attributes[$key] = $this->mutateAttributeForArray(
                $key,
                $attributes[$key]
            );
        }

        return $attributes;
    }

    /**
     * Add the casted attributes to the attributes array.
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

            // Here we will cast the attribute. Then, if the cast is a date or datetime cast
            // then we will serialize the date for the array. This will convert the dates
            // to strings based on the date format specified for these Eloquent models.
            $attributes[$key] = $this->castAttribute(
                $key,
                $attributes[$key]
            );

            // If the attribute cast was a date or a datetime, we will serialize the date as
            // a string. This allows the developers to customize how dates are serialized
            // into an array without affecting how they are persisted into the storage.
            if (
                $attributes[$key]
                && ($value === 'date' || $value === 'datetime')
            ) {
                $attributes[$key] = $this->serializeDate($attributes[$key]);
            }

            if ($attributes[$key] && $this->isCustomDateTimeCast($value)) {
                $attributes[$key] = $attributes[$key]->translatedFormat(explode(':', $value, 2)[1]);
            }

            if (
                $attributes[$key] && $attributes[$key] instanceof DateTimeInterface
                && $this->isClassCastable($key)
            ) {
                $attributes[$key] = $this->serializeDate($attributes[$key]);
            }

            if ($attributes[$key] && $this->isClassSerializable($key)) {
                $attributes[$key] = $this->serializeClassCastableAttribute($key, $attributes[$key]);
            }

            if ($attributes[$key] instanceof Arrayable) {
                $attributes[$key] = $attributes[$key]->toArray();
            }
        }

        return $attributes;
    }

    /**
     * Get an attribute array of all arrayable attributes.
     */
    protected function getArrayableAttributes(): array
    {
        return $this->getArrayableItems($this->getAttributes());
    }

    /**
     * Get all of the appendable values that are arrayable.
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
     * Get the model's relationships in array form.
     */
    public function relationsToArray(): array
    {
        $attributes = [];

        foreach ($this->getArrayableRelations() as $key => $value) {
            // If the values implements the Arrayable interface we can just call this
            // toArray method on the instances which will convert both models and
            // collections to their proper array form and we'll set the values.
            if ($value instanceof Arrayable) {
                $relation = $value->toArray();
            }

            // If the value is null, we'll still go ahead and set it in this list of
            // attributes since null is used to represent empty relationships if
            // if it a has one or belongs to type relationships on the models.
            elseif (null === $value) {
                $relation = $value;
            }

            // If the relationships snake-casing is enabled, we will snake case this
            // key so that the relation attribute is snake cased in this returned
            // array to the developers, making this consistent with attributes.
            if (static::$snakeAttributes) {
                $key = Text::snake($key);
            }

            // If the relation value has been set, we will set it on this attributes
            // list for returning. If it was not arrayable or null, we'll not set
            // the value on the array because it is some type of invalid value.
            if (isset($relation) || null === $value) {
                $attributes[$key] = $relation;
            }

            unset($relation);
        }

        return $attributes;
    }

    /**
     * Get an attribute array of all arrayable relations.
     */
    protected function getArrayableRelations(): array
    {
        return $this->getArrayableItems($this->relations);
    }

    /**
     * Get an attribute array of all arrayable values.
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
     * Get an attribute from the model.
     */
    public function getAttribute(string $key): mixed
    {
        if (! $key) {
            return null;
        }

        // If the attribute exists in the attribute array or has a "get" mutator we will
        // get the attribute's value. Otherwise, we will proceed as if the developers
        // are asking for a relationship's value. This covers both types of values.
        if (
            array_key_exists($key, $this->attributes)
            || array_key_exists($key, $this->casts)
            || $this->hasGetMutator($key)
            || $this->isClassCastable($key)
        ) {
            return $this->getAttributeValue($key);
        }

        // Here we will determine if the model base class itself contains this given key
        // since we don't want to treat any of those methods as relationships because
        // they are all intended as helper methods and none of these are relations.
        if (method_exists(self::class, $key)) {
            return null;
        }

        return $this->getRelationValue($key);
    }

    /**
     * Get a plain attribute (not a relationship).
     */
    public function getAttributeValue(string $key): mixed
    {
        return $this->transformModelValue($key, $this->getAttributeFromArray($key));
    }

    /**
     * Get an attribute from the $attributes array.
     */
    protected function getAttributeFromArray(string $key): mixed
    {
        return $this->getAttributes()[$key] ?? null;
    }

    /**
     * Get a relationship.
     */
    public function getRelationValue(string $key): mixed
    {
        // If the key already exists in the relationships array, it just means the
        // relationship has already been loaded, so we'll just return it out of
        // here because there is no need to query within the relations twice.
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        // If the "attribute" exists as a method on the model, we will just assume
        // it is a relationship and will load and return results from the query
        // and hydrate the relationship's value on the "relationships" array.
        if (
            method_exists($this, $key)
            || (static::$relationResolvers[static::class][$key] ?? null)
        ) {
            return $this->getRelationshipFromMethod($key);
        }

        return null;
    }

    /**
     * Get a relationship value from a method.
     *
     * @throws LogicException
     */
    protected function getRelationshipFromMethod(string $method): mixed
    {
        $relation = $this->{$method}();

        if (! $relation instanceof Relation) {
            if (null === $relation) {
                throw new LogicException(sprintf(
                    '%s::%s must return a relationship instance, but "null" was returned. Was the "return" keyword used?',
                    static::class,
                    $method
                ));
            }

            throw new LogicException(sprintf(
                '%s::%s must return a relationship instance.',
                static::class,
                $method
            ));
        }

        return Helpers::tap($relation->getResults(), function ($results) use ($method) {
            $this->setRelation($method, $results);
        });
    }

    /**
     * Determine if a get mutator exists for an attribute.
     */
    public function hasGetMutator(string $key): bool
    {
        return method_exists($this, 'get' . Text::studly($key) . 'Attribute');
    }

    /**
     * Get the value of an attribute using its mutator.
     */
    protected function mutateAttribute(string $key, mixed $value): mixed
    {
        return $this->{'get' . Text::studly($key) . 'Attribute'}($value);
    }

    /**
     * Get the value of an attribute using its mutator for array conversion.
     */
    protected function mutateAttributeForArray(string $key, mixed $value): mixed
    {
        $value = $this->isClassCastable($key)
                    ? $this->getClassCastableAttributeValue($key, $value)
                    : $this->mutateAttribute($key, $value);

        return $value instanceof Arrayable ? $value->toArray() : $value;
    }

    /**
     * Merge new casts with existing casts on the model.
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

        if ($this->isClassCastable($key)) {
            return $this->getClassCastableAttributeValue($key, $value);
        }

        return $value;
    }

    /**
     * Cast the given attribute using a custom cast class.
     */
    protected function getClassCastableAttributeValue(string $key, mixed $value): mixed
    {
        if (isset($this->classCastCache[$key])) {
            return $this->classCastCache[$key];
        }
        $caster = $this->resolveCasterClass($key);

        $value = $caster instanceof CastsInboundAttributes
                    ? $value
                    : $caster->get($this, $key, $value, $this->attributes);

        if ($caster instanceof CastsInboundAttributes || ! is_object($value)) {
            unset($this->classCastCache[$key]);
        } else {
            $this->classCastCache[$key] = $value;
        }

        return $value;
    }

    /**
     * Get the type of cast for a model attribute.
     */
    protected function getCastType(string $key): string
    {
        if ($this->isCustomDateTimeCast($this->getCasts()[$key])) {
            return 'custom_datetime';
        }

        if ($this->isDecimalCast($this->getCasts()[$key])) {
            return 'decimal';
        }

        return trim(strtolower($this->getCasts()[$key]));
    }

    /**
     * Increment or decrement the given attribute using the custom cast class.
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
     * Serialize the given attribute using the custom cast class.
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
     * Determine if the cast type is a custom date time cast.
     */
    protected function isCustomDateTimeCast(string $cast): bool
    {
        return strncmp($cast, 'date:', 5) === 0
               || strncmp($cast, 'datetime:', 9) === 0;
    }

    /**
     * Determine if the cast type is a decimal cast.
     */
    protected function isDecimalCast(string $cast): bool
    {
        return strncmp($cast, 'decimal:', 8) === 0;
    }

    /**
     * Set a given attribute on the model.
     */
    public function setAttribute(string $key, mixed $value): mixed
    {
        // First we will check for the presence of a mutator for the set operation
        // which simply lets the developers tweak the attribute as it is set on
        // this model, such as "json_encoding" a listing of data for storage.
        if ($this->hasSetMutator($key)) {
            return $this->setMutatedAttributeValue($key, $value);
        }

        // If an attribute is listed as a "date", we'll convert it from a DateTime
        // instance into a form proper for storage on the database tables using
        // the connection grammar's date format. We will auto set the values.
        if ($value && $this->isDateAttribute($key)) {
            $value = $this->fromDateTime($value);
        }

        if ($this->isClassCastable($key)) {
            $this->setClassCastableAttribute($key, $value);

            return $this;
        }

        if (null !== $value && $this->isJsonCastable($key)) {
            $value = $this->castAttributeAsJson($key, $value);
        }

        // If this attribute contains a JSON ->, we'll set the proper value in the
        // attribute's underlying array. This takes care of properly nesting an
        // attribute in the array's value in the case of deeply nested items.
        if (Text::contains($key, '->')) {
            return $this->fillJsonAttribute($key, $value);
        }

        if (null !== $value && $this->isEncryptedCastable($key)) {
            $value = $this->castAttributeAsEncryptedString($key, $value);
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Determine if a set mutator exists for an attribute.
     */
    public function hasSetMutator(string $key): bool
    {
        return method_exists($this, 'set' . Text::studly($key) . 'Attribute');
    }

    /**
     * Set the value of an attribute using its mutator.
     */
    protected function setMutatedAttributeValue(string $key, mixed $value): mixed
    {
        return $this->{'set' . Text::studly($key) . 'Attribute'}($value);
    }

    /**
     * Determine if the given attribute is a date or date castable.
     */
    protected function isDateAttribute(string $key): bool
    {
        return in_array($key, $this->getDates(), true)
               || $this->isDateCastable($key);
    }

    /**
     * Set a given JSON attribute on the model.
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

        return $this;
    }

    /**
     * Set the value of a class castable attribute.
     */
    protected function setClassCastableAttribute(string $key, mixed $value): void
    {
        $caster = $this->resolveCasterClass($key);

        if (null === $value) {
            $this->attributes = array_merge($this->attributes, array_map(
                static function () {
                },
                $this->normalizeCastClassResponse($key, $caster->set(
                    $this,
                    $key,
                    $this->{$key},
                    $this->attributes
                ))
            ));
        } else {
            $this->attributes = array_merge(
                $this->attributes,
                $this->normalizeCastClassResponse($key, $caster->set(
                    $this,
                    $key,
                    $value,
                    $this->attributes
                ))
            );
        }

        if ($caster instanceof CastsInboundAttributes || ! is_object($value)) {
            unset($this->classCastCache[$key]);
        } else {
            $this->classCastCache[$key] = $value;
        }
    }

    /**
     * Get an array attribute with the given key and value set.
     */
    protected function getArrayAttributeWithValue(string $path, string $key, mixed $value): self
    {
        return Helpers::tap($this->getArrayAttributeByKey($key), static function (&$array) use ($path, $value) {
            Arr::set($array, str_replace('->', '.', $path), $value);
        });
    }

    /**
     * Get an array attribute or return an empty array if it is not set.
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
     * Cast the given attribute to JSON.
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
     * Encode the given value as JSON.
     */
    protected function asJson(mixed $value): string
    {
        return json_encode($value);
    }

    /**
     * Decode the given JSON back into an array or object.
     */
    public function fromJson(string $value, bool $asObject = false): mixed
    {
        return json_decode($value, ! $asObject);
    }

    /**
     * Decrypt the given encrypted string.
     */
    public function fromEncryptedString(string $value): mixed
    {
        return static::$encrypter->decrypt($value, false);
    }

    /**
     * Cast the given attribute to an encrypted string.
     */
    protected function castAttributeAsEncryptedString(string $key, mixed $value): string
    {
        return static::$encrypter->encrypt($value, false);
    }

    /**
     * Set the encrypter instance that will be used to encrypt attributes.
     *
     * @param \CodeIgniter\Encryption\EncrypterInterface $encrypter
     */
    public static function encryptUsing($encrypter): void
    {
        static::$encrypter = $encrypter;
    }

    /**
     * Decode the given float.
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
     * Return a decimal as string.
     */
    protected function asDecimal(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', '');
    }

    /**
     * Return a timestamp as DateTime object with time set to 00:00:00
     */
    protected function asDate(mixed $value): Date
    {
        return $this->asDateTime($value)->startOfDay();
    }

    /**
     * Return a timestamp as DateTime object.
     */
    protected function asDateTime(mixed $value): Date
    {
        // If this value is already a Carbon instance, we shall just return it as is.
        // This prevents us having to re-instantiate a Carbon instance when we know
        // it already is one, which wouldn't be fulfilled by the DateTime check.
        if ($value instanceof Date) {
            return Date::createFromInstance($value);
        }

        // If the value is already a DateTime instance, we will just skip the rest of
        // these checks since they will be a waste of time, and hinder performance
        // when checking the field. We will just return the DateTime right away.
        if ($value instanceof DateTimeInterface) {
            return Date::parse(
                $value->format('Y-m-d H:i:s.u'),
                $value->getTimezone()
            );
        }

        // If this value is an integer, we will assume it is a UNIX timestamp's value
        // and format a Carbon object from this timestamp. This allows flexibility
        // when defining your date fields as they might be UNIX timestamps here.
        if (is_numeric($value)) {
            return Date::createFromTimestamp($value);
        }

        // If the value is in simply year, month, day format, we will instantiate the
        // Carbon instances from that format. Again, this provides for simple date
        // fields on the database, while still supporting Carbonized conversion.
        if ($this->isStandardDateFormat($value)) {
            return Date::createFrominstance(Date::createFromFormat('Y-m-d', $value)->startOfDay());
        }

        $format = $this->getDateFormat();

        // Finally, we will just assume this date is in the format used by default on
        // the database connection and use that format to create the Carbon object
        // that is returned back out to the developers after we convert it here.
        try {
            $date = Date::createFromFormat($format, $value);
        } catch (InvalidArgumentException $e) {
            $date = false;
        }

        return $date ?: Date::parse($value);
    }

    /**
     * Determine if the given value is a standard date format.
     */
    protected function isStandardDateFormat(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value);
    }

    /**
     * Convert a DateTime to a storable string.
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
     * Prepare a date for array / JSON serialization.
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return Date::createFromInstance($date)->format($this->dateFormat);
    }

    /**
     * Get the attributes that should be converted to dates.
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
    public function hasCast(string $key, array|string|null $types = null): bool
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
        if (! array_key_exists($key, $this->getCasts())) {
            return false;
        }

        $castType = $this->parseCasterClass($this->getCasts()[$key]);

        if (in_array($castType, static::$primitiveCastTypes, true)) {
            return false;
        }

        if (class_exists($castType)) {
            return true;
        }

        throw new InvalidCastException($this->getModel(), $key, $castType);
    }

    /**
     * Determine if the key is deviable using a custom class.
     *
     * @throws InvalidCastException
     */
    protected function isClassDeviable(string $key): bool
    {
        return $this->isClassCastable($key)
            && method_exists($castType = $this->parseCasterClass($this->getCasts()[$key]), 'increment')
            && method_exists($castType, 'decrement');
    }

    /**
     * Determine if the key is serializable using a custom class.
     *
     * @throws InvalidCastException
     */
    protected function isClassSerializable(string $key): bool
    {
        return $this->isClassCastable($key)
               && method_exists($this->parseCasterClass($this->getCasts()[$key]), 'serialize');
    }

    /**
     * Resolve the custom caster class for a given key.
     */
    protected function resolveCasterClass(string $key): mixed
    {
        $castType = $this->getCasts()[$key];

        $arguments = [];

        if (is_string($castType) && strpos($castType, ':') !== false) {
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
        $class = strpos($class, ':') === false
                        ? $class
                        : explode(':', $class, 2)[0];
        if ($class[0] === '?') {
            $class = substr($class, 1);
        }

        return $class;
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
        $this->mergeAttributesFromClassCasts();

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

        $this->classCastCache = [];

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
    protected function hasChanges(array $changes, array|string|null $attributes = null): bool
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
        if ($this->isDateAttribute($key)) {
            return $this->fromDateTime($attribute) ===
                   $this->fromDateTime($original);
        }
        if ($this->hasCast($key, ['object', 'collection'])) {
            return $this->castAttribute($key, $attribute) ===
                $this->castAttribute($key, $original);
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

        // If the attribute exists within the cast array, we will convert it to
        // an appropriate native PHP type dependent upon the associated value
        // given with the key in the pair. Dayle made this comment line up.
        if ($this->hasCast($key)) {
            return $this->castAttribute($key, $value);
        }

        // If the attribute is listed as a date, we will convert it to a DateTime
        // instance on retrieval, which makes it quite convenient to work with
        // date fields without having to create a mutator for each property.
        if (
            $value !== null
            && \in_array($key, $this->getDates(), false)
        ) {
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
        $this->appends = array_unique(
            array_merge($this->appends, is_string($attributes) ? func_get_args() : $attributes)
        );

        return $this;
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
        $class = static::class;

        if (! isset(static::$mutatorCache[$class])) {
            static::cacheMutatedAttributes($class);
        }

        return static::$mutatorCache[$class];
    }

    /**
     * Extract and cache all the mutated attributes of a class.
     */
    public static function cacheMutatedAttributes(string $class): void
    {
        static::$mutatorCache[$class] = Helpers::collect(static::getMutatorMethods($class))->map(static fn ($match) => lcfirst(static::$snakeAttributes ? Text::snake($match) : $match))->all();
    }

    /**
     * Get all of the attribute mutator methods.
     */
    protected static function getMutatorMethods(mixed $class): array
    {
        preg_match_all('/(?<=^|;)get([^;]+?)Attribute(;|$)/', implode(';', get_class_methods($class)), $matches);

        return $matches[1];
    }
}
