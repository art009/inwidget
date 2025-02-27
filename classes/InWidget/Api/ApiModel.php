<?php

namespace aik27\inwidget\InWidget\Api;

/**
 * Project:     inWidget: show pictures from instagram.com on your site!
 * File:        ApiModel.php
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of MIT license
 * https://inwidget.ru/MIT-license.txt
 *
 * @link https://inwidget.ru
 * @copyright 2014-2020 Alexandr Kazarmshchikov
 * @author Alexandr Kazarmshchikov
 * @package inWidget\Api
 *
 */

abstract class ApiModel
{

    /**
     * Abstract
     *
     * @param string $login
     * @param string $token - access token [some API drivers may not required this]
     * @return array
     */

    abstract public function getAccountByLogin($login, $token);

    /**
     * Abstract
     *
     * @param string $login
     * @param string $token - access token [some API drivers may not required this]
     * @param int $count - maximum medias per page
     * @param int $maxId - return media earlier than this max_id
     * @return array
     */

    abstract public function getMediasByLogin($login, $token, $count, $maxId);

    /**
     * Abstract
     *
     * @param string $tag
     * @param string $token - access token [some API drivers may not required this]
     * @param int $count - maximum medias per page
     * @param string $maxId - return media earlier than this max_tag_id
     * @return array
     */

    abstract public function getMediasByTag($tag, $token, $count, $maxId);

    /**
     * Abstract
     *
     * @param string $tag
     * @param string $login
     * @param string $token - access token [some API drivers may not required this]
     * @param int $count - maximum medias per page
     * @param string $maxId - return media earlier than this max_id
     * @return array
     */

    abstract public function getMediasByTagFromAccount($tag, $login, $token, $count, $maxId);

    /**
     * Abstract
     *
     * @param object $account
     * @return array
     */

    abstract protected function prepareAccountData($account);

    /**
     * Abstract
     *
     * @param object $medias
     * @return array
     */

    abstract protected function prepareMediasData($medias);

    /**
     * Remove special characters from tag
     *
     * @param string $tag
     * @return string
     */
    public static function prepareTag($tag)
    {
        $tag = str_replace('#', '', $tag);
        $tag = strtolower($tag);
        $tag = trim($tag);
        return $tag;
    }

    /**
     * Get API driver
     *
     * @param string $drive [optional]
     * @param string $login [optional]
     * @param string $password [optional]
     * @return object
     */
    public static function getInstance($drive = '', $login = '', $password = '')
    {
        switch ($drive) {
            case 'official':
                return new apiOfficial();
                break;
            default:
                return new apiScraper($login, $password);
                break;
        }
    }
}
