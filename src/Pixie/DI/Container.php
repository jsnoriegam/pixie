<?php namespace Pixie\DI;

class Container {

    /**
    * 
    */
    private $instances = [];

    /**
     * Register or replace an instance as a singleton.
     * Useful for replacing with Mocked instance
     *
     * @param  string $key
     * @param  mixed  $instance
     *
     * @return void
     */
    public function setInstance($key, $value) {
        $this->instances[$key] = $value;
    }

    /**
     * Build from the given key.
     * @param  string $key
     * @param  array  $parameters
     *
     * @return mixed
     */
    public function build($key, $args = []) {
        // If we have a instance registered then just return it
        if(array_key_exists($key, $this->instances)) {
            return $this->instances[$key];
        } else {
            // If we don't have a registered object with the key then assume user
            // is trying to build a class with the given key/name
            if(class_exists($key)) {
                $class = new \ReflectionClass($key);
                $instance = $class->newInstanceArgs($args);
                return $instance;
            }

            throw new \InvalidArgumentException(sprintf('Provided key "%s" is not a valid class reference', $key));
        }
    }
}