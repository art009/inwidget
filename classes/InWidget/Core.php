<?php

namespace aik27\inwidget\InWidget;

use aik27\inwidget\InWidget\Api\ApiModel;
use aik27\inwidget\InWidget\Exception\InWidgetException;

/**
 * Project:     inWidget: show pictures from instagram.com on your site!
 * File:        Core.php
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of MIT license
 * https://inwidget.ru/MIT-license.txt
 *
 * @link https://inwidget.ru
 * @copyright 2014-2020 Alexandr Kazarmshchikov
 * @author Alexandr Kazarmshchikov
 * @package inWidget
 *
 */

class Core
{
    public $config = [];
    public $data = [];
    public $api = false;
    private $account = false;
    private $medias = false;
    private $banned = [];
    public $width = 260;
    public $inline = 4;
    public $view = 12;
    public $toolbar = true;
    public $adaptive = false;
    public $preview = 'large';
    public $imgWidth = 0;
    public $skipGET = false;
    public $loginAvailable = [];
    public $tagsAvailable = [];
    public $lang = [];
    public $langName = '';
    public $langAvailable = ['ru', 'en', 'ua'];
    private $langPath = 'langs/';
    private $cachePath = 'cache/';
    private $cacheFile = '{$fileName}.txt';
    public $skinName = 'default';
    public $skinPath = 'skins/';
    public $skinAvailable = [
        'default',
        'modern-blue',
        'modern-green',
        'modern-red',
        'modern-orange',
        'modern-grey',
        'modern-black',
        'modern-violet',
        'modern-yellow',
    ];

    /**
     * @param array $config [optional] - like config.php
     * @return null
     * @throws InWidgetException
     */
    public function __construct($config = [])
    {
        if (!empty($config)) {
            $this->config = $config;
        } else {
            require_once 'config.php';
            $this->config = $CONFIG;
        }
        $this->checkConfig();
        $this->checkCacheRights();
        $this->setLang();
        $this->setSkin();
        $this->setOptions();
        try {
            if (!empty($this->config['ACCESS_TOKEN'])) {
                $this->api = ApiModel::getInstance('official');
            } else {
                $this->api = ApiModel::getInstance('', $config['authLogin'], $config['authPassword']);
            }
        } catch (\Exception $e) {
            throw new InWidgetException($e->getMessage(), 500, $this->getCacheFilePath());
        }
    }

    /**
     * Send request to Instagram
     *
     * @return null
     * @throws InWidgetException
     */
    private function apiQuery()
    {
        try {
            $this->account = $this->api->getAccountByLogin($this->config['LOGIN'], $this->config['ACCESS_TOKEN']);
            // by hashtag
            if (!empty($this->config['HASHTAG'])) {
                $mediaArray = [];
                $tags = explode(',', $this->config['HASHTAG']);
                if (!empty($tags)) {
                    foreach ($tags as $key => $item) {
                        if (!empty($item)) {
                            if ($this->config['tagsFromAccountOnly'] === true) {
                                $mediaArray[] = $this->api->getMediasByTagFromAccount(
                                    $item,
                                    $this->config['LOGIN'],
                                    $this->config['ACCESS_TOKEN'],
                                    $this->config['imgCount']
                                );
                            } else {
                                $mediaArray[] = $this->api->getMediasByTag(
                                    $item,
                                    $this->config['ACCESS_TOKEN'],
                                    $this->config['imgCount']
                                );
                            }
                        }
                    }
                }
                $medias = [];
                if (!empty($mediaArray)) {
                    foreach ($mediaArray as $key => $item) {
                        $medias = array_merge($medias, $item);
                    }
                }
                $this->medias = $medias;
                unset($mediaArray, $medias);
            } else {
                $this->medias = $this->api->getMediasByLogin(
                    $this->config['LOGIN'],
                    $this->config['ACCESS_TOKEN'],
                    $this->config['imgCount']
                );
            }
        } catch (\Exception $e) {
            throw new InWidgetException($e->getMessage(), 500, $this->getCacheFilePath());
        }
        // Get banned ids. Ignore any errors
        if (!empty($this->config['tagsBannedLogins'])) {
            foreach ($this->config['tagsBannedLogins'] as $key => $item) {
                try {
                    $banned = $this->api->getAccountByLogin($item['login'], $this->config['ACCESS_TOKEN']);
                    $this->config['tagsBannedLogins'][$key]['id'] = $banned['userid'];
                } catch (\Exception $e) {
                }
            }
            $this->banned = $this->config['tagsBannedLogins'];
        }
    }

    /**
     * Get data from Instagram (or actual cache)
     *
     * @return object
     * @throws \Exception
     * @throws InWidgetException
     */
    public function getData()
    {
        $this->data = $this->getCache();
        if (empty($this->data)) {
            $this->apiQuery();
            $this->createCache();
            $this->data = json_decode(file_get_contents($this->getCacheFilePath()));
        }
        if (!is_object($this->data)) {
            $this->data = $this->getBackup();
            if (!is_object($this->data)) {
                $this->data = $this->getCache();
                throw new \Exception('<b style="color:red;">Cache file contains plain text:</b><br />' . $this->data);
            } else {
                $this->data->isBackup = true;
            }
        }
        return $this->data;
    }

    /**
     * Get data independent of API names policy
     * @return array
     */
    private function prepareData()
    {
        $data = $this->account;
        $data['banned'] = $this->banned;
        $data['tags'] = $this->config['HASHTAG'];
        $data['images'] = $this->medias;
        $data['lastupdate'] = time();
        return $data;
    }

    /**
     * @return mixed
     * @throws InWidgetException
     */
    private function getCache()
    {
        if ($this->config['cacheSkip'] === true) {
            return false;
        }
        $mtime = @filemtime($this->getCacheFilePath());
        if ($mtime <= 0) {
            throw new InWidgetException(
                'Can\'t get modification time of <b>{$cacheFile}</b>. Cache always be expired.',
                102,
                $this->getCacheFilePath()
            );
        }
        $cacheExpTime = $mtime + ($this->config['cacheExpiration'] * 60 * 60);
        if (time() > $cacheExpTime) {
            return false;
        } else {
            $rawData = file_get_contents($this->getCacheFilePath());
            $cacheData = json_decode($rawData);
            if (!is_object($cacheData)) {
                return $rawData;
            }
            unset($rawData);
        }
        return $cacheData;
    }

    /**
     * @return mixed
     */
    private function getBackup()
    {
        $file = $this->getCacheFilePath() . '_backup';
        if (file_exists($file)) {
            $rawData = file_get_contents($file);
            $cacheData = json_decode($rawData);
            if (!is_object($cacheData)) {
                return $rawData;
            } else {
                return $cacheData;
            }
        }
    }

    /**
     * @return null
     */
    private function createCache()
    {
        $data = json_encode($this->prepareData());
        file_put_contents($this->getCacheFilePath(), $data, LOCK_EX);
        file_put_contents($this->getCacheFilePath() . '_backup', $data, LOCK_EX);
    }

    /**
     * @return string
     */
    public function getCacheFilePath()
    {
        return $this->cachePath . '' . $this->cacheFile;
    }

    /**
     * Check important values and prepare to work
     *
     * @return null
     * @throws \Exception
     */
    private function checkConfig()
    {
        if (!empty($this->config['skinAvailable'])) {
            $this->skinAvailable = $this->config['skinAvailable'];
        }
        if (!empty($this->config['langAvailable'])) {
            $this->langAvailable = $this->config['langAvailable'];
        }
        if (!empty($this->config['loginAvailable'])) {
            $this->loginAvailable = $this->config['loginAvailable'];
        }
        if (!empty($this->config['tagsAvailable'])) {
            $this->tagsAvailable = $this->config['tagsAvailable'];
        }
        if (empty($this->config['LOGIN'])) {
            throw new \Exception(__CLASS__ . ': LOGIN required in config.php');
        }
        if (!in_array($this->config['langDefault'], $this->langAvailable, true)) {
            throw new \Exception(__CLASS__ . ': default language does not present in "langAvailable" config property');
        }
        if (!in_array($this->config['skinDefault'], $this->skinAvailable, true)) {
            throw new \Exception(__CLASS__ . ': default skin does not present in "skinAvailable" config property');
        }
        // prepare paths
        $this->langPath = __DIR__ . '/' . $this->langPath; // PHP < 5.6 fix
        $this->cachePath = __DIR__ . '/' . $this->cachePath; // PHP < 5.6 fix
        // prepare login
        if ($this->skipGET === false) {
            if (isset($_GET['login'])) {
                if (in_array($_GET['login'], $this->loginAvailable)) {
                    $this->config['LOGIN'] = $_GET['login'];
                    // login priority by default tags
                    $this->config['HASHTAG'] = "";
                } else {
                    throw new \Exception(__CLASS__ . ': login does not present in "loginAvailable" config property');
                }
            }
        }
        $this->config['LOGIN'] = strtolower(trim($this->config['LOGIN']));
        $cacheFileName = md5($this->config['LOGIN']);
        // prepare hashtags
        if ($this->skipGET === false) {
            if (isset($_GET['tag'])) {
                if (in_array($_GET['tag'], $this->tagsAvailable)) {
                    $this->config['HASHTAG'] = urldecode($_GET['tag']);
                } else {
                    throw new \Exception(__CLASS__ . ': tag does not present in "tagsAvailable" config property');
                }
            }
        }
        if (!empty($this->config['HASHTAG'])) {
            $this->config['HASHTAG'] = trim($this->config['HASHTAG']);
            $this->config['HASHTAG'] = str_replace('#', '', $this->config['HASHTAG']);
            $cacheFileName = md5($cacheFileName . $this->config['HASHTAG'] . '_tags');
        }
        if (!empty($this->config['skinPath'])) {
            $this->skinPath = $this->config['skinPath'];
        }
        if (!empty($this->config['cachePath'])) {
            $this->cachePath = $this->config['cachePath'];
        }
        if (!empty($this->config['langPath'])) {
            $this->langPath = $this->config['langPath'];
        }
        $this->cacheFile = str_replace('{$fileName}', $cacheFileName, $this->cacheFile);
        if (!empty($this->config['tagsBannedLogins'])) {
            $logins = explode(',', $this->config['tagsBannedLogins']);
            if (!empty($logins)) {
                $this->config['tagsBannedLogins'] = [];
                foreach ($logins as $key => $item) {
                    $item = strtolower(trim($item));
                    $this->config['tagsBannedLogins'][$key]['login'] = $item;
                }
            }
        } else {
            $this->config['tagsBannedLogins'] = [];
        }
    }

    /**
     * Let me know if cache file not writable
     *
     * @return null
     * @throws InWidgetException
     */
    private function checkCacheRights()
    {
        $cacheFile = @fopen($this->getCacheFilePath(), 'a+b');
        if (!is_resource($cacheFile)) {
            throw new InWidgetException(
                'Can\'t get access to file <b>{$cacheFile}</b>. Check file path or permissions.',
                101,
                $this->getCacheFilePath()
            );
        }
        fclose($cacheFile);
    }

    /**
     * Set widget lang
     * New value must be present in langAvailable property necessary
     *
     * @param string $name [optional]
     * @return null
     */
    public function setLang($name = '')
    {
        if (empty($name) and $this->config['langAuto'] === true and !empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $name = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
        }
        if (!empty($name) and in_array($name, $this->langAvailable, true)) {
            $name = strtolower($name);
            if (file_exists($this->langPath . $name . '.php')) {
                $this->langName = $name;
                require $this->langPath . $name . '.php';
            }
        }
        if (empty($LANG)) {
            $this->langName = $this->config['langDefault'];
            require $this->langPath . $this->config['langDefault'] . '.php';
        }
        $this->lang = $LANG;
    }

    /**
     * Set widget skin
     * New value must be present in skinAvailable property necessary
     *
     * @param string $name [optional]
     * @return null
     */
    public function setSkin($name = '')
    {
        if (!empty($name) and in_array($name, $this->skinAvailable, true)) {
            $this->skinName = $name;
        } else {
            $this->skinName = $this->config['skinDefault'];
        }
    }

    /**
     * Set new values of properties through the $_GET
     *
     * @return null
     */
    public function setOptions()
    {
        $this->width -= 2;
        if ($this->skipGET === false) {
            if (isset($_GET['width']) and (int)$_GET['width'] > 0) {
                $this->width = $_GET['width'] - 2;
            }
            if (isset($_GET['inline']) and (int)$_GET['inline'] > 0) {
                $this->inline = $_GET['inline'];
            }
            if (isset($_GET['view']) and (int)$_GET['view'] > 0) {
                $this->view = $_GET['view'];
            }
            if (isset($_GET['toolbar']) and $_GET['toolbar'] == 'false' or !empty($this->config['HASHTAG'])) {
                $this->toolbar = false;
            }
            if (isset($_GET['adaptive']) and $_GET['adaptive'] == 'true') {
                $this->adaptive = true;
            }
            if (isset($_GET['preview'])) {
                $this->preview = $_GET['preview'];
            }
            if (isset($_GET['lang'])) {
                $this->setLang($_GET['lang']);
            }
            if (isset($_GET['skin'])) {
                $this->setSkin($_GET['skin']);
            }
        }
        if ($this->width > 0) {
            $this->imgWidth = round(($this->width - (17 + (9 * $this->inline))) / $this->inline);
        }
    }

    /**
     * Let me know if this user was banned
     *
     * @param int $id
     * @return bool
     */
    public function isBannedUserId($id)
    {
        if (!empty($this->data->banned)) {
            foreach ($this->data->banned as $key1 => $cacheValue) {
                if (!empty($cacheValue->id) and $cacheValue->id === $id) {
                    if (!empty($this->config['tagsBannedLogins'])) {
                        foreach ($this->config['tagsBannedLogins'] as $key2 => $configValue) {
                            if ($configValue['login'] === $cacheValue->login) {
                                return true;
                            }
                        }
                    }
                }
            }
        }
        return false;
    }

    /**
     * Get number of images without images of banned users
     *
     * @param object $images
     * @return int
     */
    public function countAvailableImages($images)
    {
        $count = 0;
        if (!empty($images)) {
            foreach ($images as $key => $item) {
                if ($this->isBannedUserId($item->authorId) == true) {
                    continue;
                }
                $count++;
            }
        }
        return $count;
    }
}
