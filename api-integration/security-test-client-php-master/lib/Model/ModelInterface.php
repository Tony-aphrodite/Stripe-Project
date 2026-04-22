<?php

namespace Security\Test\Model;


interface ModelInterface
{
    
    public function getModelName();

    
    public static function swaggerTypes();

    
    public static function swaggerFormats();

    
    public static function attributeMap();

    
    public static function setters();

    
    public static function getters();

    
    public function listInvalidProperties();

    
    public function valid();
}
