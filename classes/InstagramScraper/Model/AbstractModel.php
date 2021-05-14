<?php

namespace aik27\inwidget\InstagramScraper\Model;

use aik27\inwidget\InstagramScraper\Traits\ArrayLikeTrait;
use aik27\inwidget\InstagramScraper\Traits\InitializerTrait;

/**
 * Class AbstractModel
 * @package InstagramScraper\Model
 */
abstract class AbstractModel implements \ArrayAccess
{
    use InitializerTrait, ArrayLikeTrait;

    /**
     * @var array
     */
    protected static $initPropertiesMap = [];

    /**
     * @return array
     */
    public static function getColumns()
    {
        return \array_keys(static::$initPropertiesMap);
    }
}