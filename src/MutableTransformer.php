<?php namespace Deefour\Transformer;

class MutableTransformer extends Transformer {

  /**
   * Constructor.
   *
   * @param  array $source [optional]
   */
  public function __construct(array $attributes = []) {
    parent::__construct($attributes);
  }

  /**
   * ArrayAccess to set an attribute on the source data.
   *
   * @return void
   */
  public function offsetSet($offset, $value) {
    $this->set($offset, $value);
  }

  /**
   * ArrayAccess to remove an attribute from the source data.
   *
   * @return mixed
   */
  public function offsetUnset($offset) {
    unset($this->attributes[ $offset ]);
  }

  /**
   * {@inheritdoc}
   *
   * Magic setter.
   *
   * @param  string $attribute
   * @param  mixed  $value
   */
  public function __set($attribute, $value) {
    $this->set($attribute, $value);
  }

  /**
   * Set an attribute on the source data.
   *
   * @param  string $attribute
   * @param  mixed  $value
   */
  public function set($attribute, $value) {
    $this->attributes[ $attribute ] = $value;
  }

}