<?php

namespace Deefour\Transformer;

use ReflectionClass;
use ReflectionMethod;
use ArrayAccess;
use Closure;
use JsonSerializable;

class Transformer implements JsonSerializable, ArrayAccess
{
    /**
     * The raw input attributes.
     *
     * @var array
     */
    protected $attributes = [];

    /**
     * Array of casts to be performed. Keys are attribute names, values are
     * type casts.
     *
     * @var array
     */
    protected $casts = [];
    
    /**
     * Default values for variables. Keys are attribute names, values are
     * the default values to be used.
     *
     * @var array
     */
    protected $defaultValues = [];

    /**
     * Constructor.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    /**
     * Retrieve a single transformed attribute.
     *
     * @param  string $attribute
     * @param  mixed  $default
     * @return mixed
     */
    public function get($attribute, $default = null)
    {
        if ($this->isAttributeMethod($attribute)) {
            $return = call_user_func([$this, $this->camelCase($attribute)]);
        }

        if ( ! $this->exists($attribute) && !isset($return)) {
            $return = ($default instanceof Closure) ? $default() : $default;
        }

        // Try to cast the attribute value.
        if ($this->hasCast($attribute) && !isset($return)) {
            $return = $this->cast($attribute);
        }

        // If no transformation has been specified, return the raw input.
        if (!isset($return)) {
            $return = $this->raw($attribute);
        }

        $defaultValue = $this->defaultValueFor($attribute);

        if (is_null($return) && $defaultValue) {
            return $defaultValue;
        }

        return $return;
    }

    /**
     * The raw attribute value. If no attribute is provided, the raw source is
     * returned (no transformation is performed).
     *
     * @param  string|null $attribute
     * @return mixed
     */
    public function raw($attribute = null)
    {
        if (is_null($attribute)) {
            return $this->attributes;
        }

        if ( ! array_key_exists($attribute, $this->attributes)) {
            return null;
        }

        return $this->attributes[$attribute];
    }

    /**
     * Transform the entire input source.
     *
     * @return array
     */
    public function all()
    {
        $transformation = [];

        foreach (array_keys($this->attributes) as $attribute) {
            $transformation[$attribute] = $this->get($attribute);
        }

        $reflector = new ReflectionClass($this);
        $methods   = $reflector->getMethods(ReflectionMethod::IS_PUBLIC);
        $mapping   = [];

        $methods = array_filter($methods, function ($method) {
            $attribute = $this->snakeCase((string)$method->getName());

            return $this->isAttributeMethod($attribute)
                && ! array_key_exists($attribute, $this->attributes);
        });

        array_walk($methods, function ($method) use (&$mapping) {
            $method = (string)$method->getName();
            $mapping[$this->snakeCase($method)] = $method;
        }, $methods);

        foreach (array_diff_key($mapping, $transformation) as $attribute => $method) {
            $transformation[$attribute] = $this->$method();
        }

        return $transformation;
    }

    /**
     * Retrieve a specific subset of the attributes from the transformation. This
     * is smart enough to understand nested sets of attributes.
     *
     * @return array
     */
    public function only()
    {
        $whitelist = array_reduce((array)func_get_args(), function ($carry, $item) {
            return array_merge($carry, (array)$item);
        }, []);

        $attributes = $this->toArray();
        $response   = [];

        foreach ($whitelist as $key => $value) {
            if (is_string($value)) { // scalar value
                $this->addPermittedValue($response, $attributes, $value);

                continue;
            }

            if ( ! is_array($value)) { // invalid structure; move on
                continue;
            }

            if (empty($value)) { // arbitrary array/collection
                $this->addPermittedCollection($response, $attributes, $key);

                continue;
            }

            if (array_key_exists($key, $attributes)) { // recursion
                $response[$key] = (new self($attributes[$key]))->only($value);
            }
        }

        return $response;
    }

    /**
     * Retrieve everything _except_ a subset of the attributes from the
     * transformation. This is smart enough to understand nested sets of attributes.
     *
     * @return array
     */
    public function except()
    {
        $blacklist = array_reduce((array)func_get_args(), function ($carry, $item) {
            return array_merge($carry, (array)$item);
        }, []);

        $attributes = $this->toArray();

        foreach ($blacklist as $key => $value) {
            if (is_string($value)) {
                unset($attributes[$value]);

                continue;
            }

            if (is_array($value)) {
                $attributes[$key] = (new self($attributes[$key]))->except($value);

                continue;
            }
        }

        return $attributes;
    }

    /**
     * Alias for except.
     *
     * @return array
     */
    public function omit()
    {
        return $this->except(func_get_args());
    }

    /**
     * Alias for except.
     *
     * @return array
     */
    public function without()
    {
        return $this->except(func_get_args());
    }

    /**
     * Boolean check whether the attribute exists on the source data, even if
     * it's null.
     *
     * @param  string $attribute
     * @param  boolean $sourceOnly  should only the raw input source's keys be cheked?
     * @return bool
     */
    public function exists($attribute, $sourceOnly = false)
    {
        return array_key_exists($attribute, $this->attributes)
            || ( ! $sourceOnly && $this->isAttributeMethod($attribute));
    }

    /**
     * Alias for exists.
     *
     * @param  string  $attribute
     * @return boolean
     */
    public function has($attribute) {
        return $this->exists($attribute);
    }

    /**
     * Alias for exists.
     *
     * @param  string  $attribute
     * @return boolean
     */
    public function contains($attribute) {
        return $this->has($attribute);
    }

    /**
     * List the name of the attributes.
     *
     * @return array
     */
    public function keys()
    {
        return array_keys($this->attributes);
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->all();
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function offsetExists($offset)
    {
        return $this->exists($offset);
    }

    /**
     * {@inheritdoc}
     *
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    /**
     * {@inheritdoc}
     *
     * This is a void method because the object attributes are immutable.
     */
    public function offsetSet($offset, $value)
    {
        //
    }

    /**
     * {@inheritdoc}
     *
     * This is a void method because the object attributes are immutable.
     */
    public function offsetUnset($offset)
    {
        //
    }

    /**
     * Fetch an array representation of the transformed attribute source.
     *
     * @return array
     */
    public function toArray()
    {
        return $this->all();
    }

    /**
     * {@inheritdoc}
     *
     * @return mixed
     */
    public function __get($attribute)
    {
        return $this->get($attribute);
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function __isset($attribute)
    {
        return $this->exists($attribute);
    }

    /**
     * Accessor via magic call.
     *
     * @param  string $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->get($method);
    }

    /**
     * Determine whether an attribute should be casted to a native type.
     *
     * @param  string $attribute
     * @return bool
     */
    protected function hasCast($attribute)
    {
        return array_key_exists($attribute, $this->casts);
    }

    /**
     * Get the type of cast for a model attribute.
     *
     * Pulled from Laravel's Illuminate\Database\Eloquent\Model::getCastType
     *
     * @param  string $key
     * @return string
     */
    protected function getCastType($key)
    {
        return trim(strtolower($this->casts[$key]));
    }

    /**
     * Cast an attribute to a native PHP type.
     *
     * Pulled from Laravel's Illuminate\Database\Eloquent\Model::castAttribute
     *
     * @param  mixed $attribute
     * @return mixed
     */
    protected function cast($attribute)
    {
        $value = $this->raw($attribute);

        if (is_null($value)) {
            return $value;
        }

        switch ($this->getCastType($attribute)) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'real':
            case 'float':
            case 'double':
                return (float) $value;
            case 'string':
                return (string) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'object':
                return json_decode($value);
            case 'array':
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }

    /**
     * Adds a specific attribute to the response object.
     *
     * @param  array  $response
     * @param  mixed  $attributes
     * @param  string $attribute
     * @return array
     */
    protected function addPermittedValue(array &$response, $attributes, $attribute)
    {
        if ( ! $this->offsetExists($attribute)) {
            return;
        }

        $response[$attribute] = $attributes[$attribute];

        return $response;
    }

    /**
     * Adds an arbitrary collection to the response object, by key.
     *
     * @param  array  $response
     * @param  mixed  $attributes
     * @param  string $attribute
     * @return array
     */
    protected function addPermittedCollection(array &$response, $attributes, $attribute)
    {
        if ( ! isset($attributes[$attribute]) || !is_array($attributes[$attribute])) {
            return;
        }

        $response[$attribute] = $attributes[$attribute];

        return $response;
    }

    /**
     * Convert a camel-case method name into a snake-case attribute name.
     *
     * @return string
     */
    protected function snakeCase($value)
    {
        if ( ! ctype_lower($value)) {
            $value = strtolower(preg_replace('/(.)(?=[A-Z])/', '$1_', $value));
            $value = preg_replace('/\s+/', '', $value);
        }

        return $value;
    }

    /**
     * Convert a snake-case attribute name into a camel-case method name.
     *
     * @return string
     */
    protected function camelCase($value)
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value))));
    }

    /**
     * Check to make sure the method does not have the @internal flag in the docblock.
     *
     * @param  string $attribute
     * @return boolean
     */
    protected function isAttributeMethod($attribute)
    {
        $method = $this->camelCase($attribute);

        if ( ! method_exists($this, $method)) {
            return false;
        }

        if ($this->exists($attribute, true)) {
            return true;
        }

        $method = new ReflectionMethod($this, $method);

        return $method->isPublic() && strpos($method->getDocComment(), '@attribute') !== false;
    }
    
    /**
     * Returns default value for a given property.
     *
     * @param  string $property
     *
     * @return mixed
     */
    protected function defaultValueFor($property)
    {
        if (isset($this->defaultValues[$property])) {
            return $this->defaultValues[$property];
        }

        return null;
    }

    /**
     * Magic method for useful debug info.
     *
     * @return array
     */
    public function __debugInfo()
    {
        return $this->all();
    }
}
