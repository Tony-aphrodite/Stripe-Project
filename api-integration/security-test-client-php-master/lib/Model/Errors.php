<?php

namespace Security\Test\Model;

use \ArrayAccess;
use \Security\Test\ObjectSerializer;


class Errors implements ModelInterface, ArrayAccess
{
    const DISCRIMINATOR = null;

    
    protected static $apihubModelName = 'Errors';

    
    protected static $apihubTypes = [
        'errors' => '\APIHub\Client\Model\Error[]'
    ];

    
    protected static $apihubFormats = [
        'errors' => null
    ];

    
    public static function swaggerTypes()
    {
        return self::$apihubTypes;
    }

    
    public static function swaggerFormats()
    {
        return self::$apihubFormats;
    }

    
    protected static $attributeMap = [
        'errors' => 'errors'
    ];

    
    protected static $setters = [
        'errors' => 'setErrors'
    ];

    
    protected static $getters = [
        'errors' => 'getErrors'
    ];

    
    public static function attributeMap()
    {
        return self::$attributeMap;
    }

    
    public static function setters()
    {
        return self::$setters;
    }

    
    public static function getters()
    {
        return self::$getters;
    }

    
    public function getModelName()
    {
        return self::$apihubModelName;
    }

    

    

    
    protected $container = [];

    
    public function __construct(array $data = null)
    {
        $this->container['errors'] = isset($data['errors']) ? $data['errors'] : null;
    }

    
    public function listInvalidProperties()
    {
        $invalidProperties = [];

        return $invalidProperties;
    }

    
    public function valid()
    {
        return count($this->listInvalidProperties()) === 0;
    }


    
    public function getErrors()
    {
        return $this->container['errors'];
    }

    
    public function setErrors($errors)
    {
        $this->container['errors'] = $errors;

        return $this;
    }
    
    public function offsetExists($offset)
    {
        return isset($this->container[$offset]);
    }

    
    public function offsetGet($offset)
    {
        return isset($this->container[$offset]) ? $this->container[$offset] : null;
    }

    
    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->container[] = $value;
        } else {
            $this->container[$offset] = $value;
        }
    }

    
    public function offsetUnset($offset)
    {
        unset($this->container[$offset]);
    }

    
    public function __toString()
    {
        if (defined('JSON_PRETTY_PRINT')) {
            return json_encode(
                ObjectSerializer::sanitizeForSerialization($this),
                JSON_PRETTY_PRINT
            );
        }

        return json_encode(ObjectSerializer::sanitizeForSerialization($this));
    }
}


