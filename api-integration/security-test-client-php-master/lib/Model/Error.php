<?php

namespace Security\Test\Model;

use \ArrayAccess;
use \APIHub\Client\ObjectSerializer;


class Error implements ModelInterface, ArrayAccess
{
    const DISCRIMINATOR = null;

    
    protected static $apihubModelName = 'Error';

    
    protected static $apihubTypes = [
        'code' => 'string',
        'message' => 'string'
    ];

    
    protected static $apihubFormats = [
        'code' => null,
        'message' => null
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
        'code' => 'code',
        'message' => 'message'
    ];

    
    protected static $setters = [
        'code' => 'setCode',
        'message' => 'setMessage'
    ];

    
    protected static $getters = [
        'code' => 'getCode',
        'message' => 'getMessage'
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
        $this->container['code'] = isset($data['code']) ? $data['code'] : null;
        $this->container['message'] = isset($data['message']) ? $data['message'] : null;
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


    
    public function getCode()
    {
        return $this->container['code'];
    }

    
    public function setCode($code)
    {
        $this->container['code'] = $code;

        return $this;
    }

    
    public function getMessage()
    {
        return $this->container['message'];
    }

    
    public function setMessage($message)
    {
        $this->container['message'] = $message;

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


