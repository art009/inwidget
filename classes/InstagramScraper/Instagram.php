<?php

namespace aik27\inwidget\InstagramScraper;

use aik27\inwidget\InstagramScraper\Exception\InstagramAuthException;
use aik27\inwidget\InstagramScraper\Exception\InstagramException;
use aik27\inwidget\InstagramScraper\Exception\InstagramNotFoundException;
use aik27\inwidget\InstagramScraper\Model\Account;
use aik27\inwidget\InstagramScraper\Model\Comment;
use aik27\inwidget\InstagramScraper\Model\Like;
use aik27\inwidget\InstagramScraper\Model\Location;
use aik27\inwidget\InstagramScraper\Model\Media;
use aik27\inwidget\InstagramScraper\Model\Story;
use aik27\inwidget\InstagramScraper\Model\Tag;
use aik27\inwidget\InstagramScraper\Model\UserStories;
//use phpFastCache\CacheManager;
use aik27\inwidget\Unirest\Request;

class Instagram
{
    const HTTP_NOT_FOUND = 404;
    const HTTP_OK = 200;
    const MAX_COMMENTS_PER_REQUEST = 300;
    const MAX_LIKES_PER_REQUEST = 300;
    const PAGING_TIME_LIMIT_SEC = 1800; // 30 mins time limit on operations that require multiple requests
    const PAGING_DELAY_MINIMUM_MICROSEC = 1000000; // 1 sec min delay to simulate browser
    const PAGING_DELAY_MAXIMUM_MICROSEC = 3000000; // 3 sec max delay to simulate browser

    private static $instanceCache;
    public $pagingTimeLimitSec = self::PAGING_TIME_LIMIT_SEC;
    public $pagingDelayMinimumMicrosec = self::PAGING_DELAY_MINIMUM_MICROSEC;
    public $pagingDelayMaximumMicrosec = self::PAGING_DELAY_MAXIMUM_MICROSEC;
    private $sessionUsername;
    private $sessionPassword;
    private $userSession;
    private $rhxGis = null;
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/66.0.3359.106 Safari/537.36';
    private static $headers = [
    	'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/50.0.2661.87 Safari/537.36',
    	'Origin' => 'https://www.instagram.com',
    	'Referer' => 'https://www.instagram.com',
    	'Connection' => 'close'
    ];
    public static $debug = false;
    public static $version = 'v0.8.17'; // source version with inWidget modifications
    /**
     * @param string $username
     * @param string $password
     * @param null $sessionFolder
     *
     * @return Instagram
     */
    public static function withCredentials($username, $password, $sessionFolder = null)
    {
    	/*
        if (is_null($sessionFolder)) {
            $sessionFolder = __DIR__ . DIRECTORY_SEPARATOR . 'sessions' . DIRECTORY_SEPARATOR;
        }
        if (is_string($sessionFolder)) {
            CacheManager::setDefaultConfig([
                'path' => $sessionFolder,
                'ignoreSymfonyNotice' => true,
            ]);
            static::$instanceCache = CacheManager::getInstance('files');
        } else {
            static::$instanceCache = $sessionFolder;
        }
        */
    	$_SESSION['inWidgetCacheManager'] = [];
        $instance = new self();
        $instance->sessionUsername = $username;
        $instance->sessionPassword = $password;
        return $instance;
    }

    /**
     * @param string $tag
     *
     * @return array
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public static function searchTagsByTagName($tag)
    {
        // TODO: Add tests and auth
        $response = Request::get(Endpoints::getGeneralSearchJsonLink($tag));
        // use a raw constant in the code is not a good idea!!
        //if ($response->code === 404) {
        if (static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        // use a raw constant in the code is not a good idea!!
        //if ($response->code !== 200) {
        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }

        $jsonResponse = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
        if (!isset($jsonResponse['status']) || $jsonResponse['status'] !== 'ok') {
            throw new InstagramException('Response code is not equal 200. Something went wrong. Please report issue.');
        }

        if (!isset($jsonResponse['hashtags']) || empty($jsonResponse['hashtags'])) {
            return [];
        }
        $hashtags = [];
        foreach ($jsonResponse['hashtags'] as $jsonHashtag) {
            $hashtags[] = Tag::create($jsonHashtag['hashtag']);
        }
        return $hashtags;
    }

    /**
     * @param \stdClass|string $rawError
     *
     * @return string
     */
    private static function getErrorBody($rawError)
    {
        if (is_string($rawError)) {
            return $rawError;
        }
        if (is_object($rawError)) {
            $str = '';
            foreach ($rawError as $key => $value) {
                $str .= ' ' . $key . ' => ' . $value . ';';
            }
            return $str;
        } else {
            return 'Unknown body format';
        }

    }

    /**
     * Set how many media objects should be retrieved in a single request
     * @param int $count
     */
    public static function setAccountMediasRequestCount($count)
    {
        Endpoints::setAccountMediasRequestCount($count);
    }

    /**
     * @param array $config
     */
    public static function setProxy(array $config)
    {
        $defaultConfig = [
            'port' => false,
            'tunnel' => false,
            'address' => false,
            'type' => CURLPROXY_HTTP,
            'timeout' => false,
            'auth' => [
                'user' => '',
                'pass' => '',
                'method' => CURLAUTH_BASIC
            ],
        ];

        $config = array_replace($defaultConfig, $config);

        Request::proxy($config['address'], $config['port'], $config['type'], $config['tunnel']);

        if (isset($config['auth'])) {
            Request::proxyAuth($config['auth']['user'], $config['auth']['pass'], $config['auth']['method']);
        }

        if (isset($config['timeout'])) {
            Request::timeout((int)$config['timeout']);
        }
    }

    /**
     * Disable proxy for all requests
     */
    public static function disableProxy()
    {
        Request::proxy('');
    }

    /**
     * @param string $username
     *
     * @return Account[]
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function searchAccountsByUsername($username)
    {
        $response = Request::get(Endpoints::getGeneralSearchJsonLink($username), $this->generateHeaders($this->userSession));
        if (static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }

        $jsonResponse = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
        if (!isset($jsonResponse['status']) || $jsonResponse['status'] !== 'ok') {
            throw new InstagramException('Response code is not equal 200. Something went wrong. Please report issue.');
        }
        if (!isset($jsonResponse['users']) || empty($jsonResponse['users'])) {
            return [];
        }

        $accounts = [];
        foreach ($jsonResponse['users'] as $jsonAccount) {
            $accounts[] = Account::create($jsonAccount['user']);
        }
        return $accounts;
    }

    /**
     * @param $session
     *
     * @return array
     */
    private function generateHeaders($session, $gisToken = null)
    {
        $headers = [];
        if ($session) {
            $cookies = '';
            foreach ($session as $key => $value) {
                $cookies .= "$key=$value; ";
            }
            $headers = [
                'cookie' => $cookies,
                'referer' => Endpoints::BASE_URL . '/',
                'x-csrftoken' => $session['csrftoken'],
            ];
        }

        if ($this->getUserAgent()) {
            $headers['user-agent'] = $this->getUserAgent();
            if (!is_null($gisToken)) {
            	$headers['x-instagram-gis'] = $gisToken;
            }
        }

        return $headers;
    }

    /**
     *
     * @return string
     */
    public function getUserAgent()
    {
        return $this->userAgent;
    }

    /**
     * @param $userAgent
     *
     * @return string
     */
    public function setUserAgent($userAgent)
    {
        return $this->userAgent = $userAgent;
    }

    /**
     * @param $userAgent
     *
     * @return null
     */
    public function resetUserAgent($userAgent)
    {
        return $this->userAgent = null;
    }

    /**
     * @param string $username
     * @param int $count
     * @param string $maxId
     *
     * @return Media[]
     * @throws InstagramException
     */
    public function getMedias($username, $count = 30, $maxId = '')
    {
    	$tryNumber = 1;
    	$account = $this->getAccount($username);
    	$index = 0;
    	$medias = [];
    	$isMoreAvailable = true;
    	while ($index < $count && $isMoreAvailable) {
    		// logic #1
    		if($tryNumber === 1) {
    			$variables = json_encode([
    				'id' => (string) $account->getId(),
    				'first' => (string) 30,
    				'after' => (string) $maxId
    			]);
    			$response = Request::get(Endpoints::getAccountMediasJsonLinkByHash($variables), $this->generateHeaders($this->userSession));
    			$arr = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
    			if ((static::HTTP_OK !== $response->code OR !is_array($arr) OR $arr['data']['user'] === null) AND !empty($this->userSession)) {
    				if(static::$debug) echo 'Iteration 1. Try endpoint 2 - ACCOUNT_MEDIAS<br />';
    				$response = Request::get(Endpoints::getAccountMediasJsonLink($account->getId(), $maxId), $this->generateHeaders($this->userSession));
    				$arr = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
    				$tryNumber = 2;
    			}
    			if (static::HTTP_OK !== $response->code OR !is_array($arr) OR $arr['data']['user'] === null) {
    				if(static::$debug) echo 'Iteration 1. Try parser<br />';
    				return $this->getMediasParser($username);
    			}
    		}
    		// logic #2
    		elseif($tryNumber === 2) {
    			$response = Request::get(Endpoints::getAccountMediasJsonLink($account->getId(), $maxId), $this->generateHeaders($this->userSession));
    			$arr = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
    			if (static::HTTP_OK !== $response->code OR !is_array($arr) OR $arr['data']['user'] === null) {
    				if(static::$debug) echo 'Iteration 2. Try parser<br />';
    				return $this->getMediasParser($username);
    			}
    		}
    		$nodes = $arr['data']['user']['edge_owner_to_timeline_media']['edges'];
    		// fix - count takes longer/has more overhead
    		if (!isset($nodes) || empty($nodes)) {
    			return [];
    		}
    		foreach ($nodes as $mediaArray) {
    			if ($index === $count) {
    				return $medias;
    			}
    			$medias[] = Media::create($mediaArray['node']);
    			$index++;
    		}
    		if (empty($nodes) || !isset($nodes)) {
    			return $medias;
    		}
    		$maxId = $arr['data']['user']['edge_owner_to_timeline_media']['page_info']['end_cursor'];
    		$isMoreAvailable = $arr['data']['user']['edge_owner_to_timeline_media']['page_info']['has_next_page'];
    	}
    	return $medias;
    }
    public function getMediasParser($username) 
    {
    	$response = Request::get(Endpoints::getAccountPageLink($username),self::$headers);
    	if (static::HTTP_NOT_FOUND === $response->code) {
    		throw new InstagramNotFoundException('Account with given username does not exist.');
    	}
    	if (static::HTTP_OK !== $response->code) {
    		throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
    	}
    	if (!preg_match('#window\._sharedData\s*=\s*(.*?)\s*;\s*</script>#', $response->raw_body, $matches)) {
    		throw new InstagramException('Unexpected page data format');
    	}
    	$data = json_decode($matches[1], true, 512, JSON_BIGINT_AS_STRING);
    	if(!isset($data['entry_data']['ProfilePage'][0]['graphql']['user'])) {
    		throw new InstagramException('Unexpected user data format');
    	}
    	if(!isset($data['entry_data']['ProfilePage'][0]['graphql']['user']['edge_owner_to_timeline_media']['edges'])) {
    		throw new InstagramException('Unexpected media data format');
    	}
    	$user = $data['entry_data']['ProfilePage'][0]['graphql']['user'];
    	$nodes = $user['edge_owner_to_timeline_media']['edges'];
    	if(!empty($nodes)) {
    		$medias = [];
    		foreach ($nodes as $mediaArray) {
    			$medias[] = Media::create($mediaArray['node']);
    		}
    	}
    	return $medias;
    }
    /**
     * Get data by query hash
     *
     * @param array $variables
     * @param string $queryHash
     *
     */
    public static function queryHashRequest($variables, $queryHash) {
    	$options = [
    			'query' => [
    					'query_hash' => $queryHash,
    					'variables' => json_encode($variables)
    			]
    	];
    	$httpQuery= http_build_query($options['query']);
    	$response = Request::get('https://instagram.com/graphql/query/?'.$httpQuery);
    	return $response;
    }
    /**
     * Decode JSON objects
     *
     * @param mixed $data
     *
     */
    public static function rDecode($data) {
    	return json_decode($data, true, 512, JSON_BIGINT_AS_STRING);
    }
    public static function getAccountStatistic($accountID) {
    	$follows = Instagram::queryHashRequest(
    		[	'id' => $accountID,
    			'first' => 0
    		], 'd04b0a864b4b54837c0d870b0e77e076'
    	);
    	$media = Instagram::queryHashRequest(
    		[	'id' => $accountID,
    			'first' => 0
    		], 'f2405b236d85e8296cf30347c9f08c2a'
    	);
    	$followed = Instagram::queryHashRequest(
    		[	'id' => $accountID,
    			'first' => 0
    		], 'c76146de99bb02f6415203be841dd25a'
    	);
    	$queryFollows = Instagram::rDecode($follows->raw_body);
    	$queryMedia = Instagram::rDecode($media->raw_body);
    	$queryFollowed = Instagram::rDecode($followed->raw_body);
    	$followsCount = $queryFollows['data']['user']['edge_follow']['count'];
    	$mediaCount = $queryMedia['data']['user']['edge_owner_to_timeline_media']['count'];
    	$followedCount = $queryFollowed['data']['user']['edge_followed_by']['count'];
    	return [
    		'follows' 	=> $followsCount,
    		'followedBy'=> $followedCount,
    		'medias' 	=> $mediaCount
    	];
    }
    /**
     * Search entry by web API
     * 
     * @param string $string - searching entry
     * @param string $type - user, place, hashtag
     *
     */
    public static function topSearch($string, $type) {
    	$options = [
    		'query' => [
    			'context' => 'blended',
    			'query' => $string,
    			'count' => 1
    		]
    	];
    	$httpQuery = http_build_query($options['query']);
    	$response = Request::get(Endpoints::TOP_SEARCH.'?'.$httpQuery);
    	if (static::HTTP_NOT_FOUND === $response->code) {
    		throw new InstagramNotFoundException('Top search URL does not exist.');
    	}
    	if (static::HTTP_OK !== $response->code) {
    		throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong.');
    	}
    	$body = Instagram::rDecode($response->raw_body);
    	$data = [];
    	switch($type) {
    		case 'user':
    			foreach ($body['users'] as $node) {
    				if ($node['user']['username'] === $string) {
    					$node['user']['id'] = $node['user']['pk'];
    					$data = $node['user'];
    					break;
    				}
    			}
    			if(empty($data)){
    				throw new InstagramNotFoundException('User "'.$string.'" does not exist.');
    			}
    			return $data;
    			break;
    		case 'place':
    			return $body['places'][0]['place'];
    			break;
    		case 'hashtag':
    			return $body['hashtags'][0]['hashtag'];
    			break;
    	}
    	return [];
    }
    /**
     * @param string $username
     *
     * @return Account
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getAccount($username)
    {
    	$account = Instagram::topSearch($username, 'user');
    	$statistic = Instagram::getAccountStatistic($account['id']);
    	
    	$account['followsCount'] = $statistic['follows'];
    	$account['followedByCount'] = $statistic['followedBy'];
    	$account['mediaCount'] = $statistic['medias'];

    	return Account::create($account);

    	/*
        $response = Request::get(Endpoints::getAccountPageLink($username), $this->generateHeaders($this->userSession));
        if (static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }

		preg_match_all('#\_sharedData \= (.*?)\;\<\/script\>#', $response->raw_body, $out);
		$userArray = json_decode($out[1][0], true, 512, JSON_BIGINT_AS_STRING);
		
        if (!isset($userArray['entry_data']['ProfilePage'][0]['graphql']['user'])) {
            throw new InstagramNotFoundException('Account with this username does not exist', 404);
        }
        $this->rhxGis = $userArray['rhx_gis'];
        return Account::create($userArray['entry_data']['ProfilePage'][0]['graphql']['user']);
        */
    	
    }
    private function getSharedDataFromPage($url = Endpoints::BASE_URL)
    {
    	$response = Request::get(rtrim($url, '/') . '/', $this->generateHeaders($this->userSession));
    	if (static::HTTP_NOT_FOUND === $response->code) {
    		throw new InstagramNotFoundException('Account with given username does not exist.');
    	}
    	if (static::HTTP_OK !== $response->code) {
    		throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
    	}
    	preg_match_all('#\_sharedData \= (.*?)\;\<\/script\>#', $response->raw_body, $out);
    	return json_decode($out[1][0], true, 512, JSON_BIGINT_AS_STRING);
    }
    private function getRhxGis()
    {
    	if ($this->rhxGis === null) {
    		$sharedData = $this->getSharedDataFromPage();
    		$this->rhxGis = $sharedData['rhx_gis'];
    	}
    	return $this->rhxGis;
    }
    private function generateGisToken($variables)
    {
    	return md5(implode(':', [$this->getRhxGis(), $variables ]));
    }
    /**
     * @param int $id
     * @param int $count
     * @param string $maxId
     *
     * @return Media[]
     * @throws InstagramException
     */
    public function getMediasByUserId($id, $count = 20, $maxId = '')
    {
        $index = 0;
        $medias = [];
        $isMoreAvailable = true;
        while ($index < $count && $isMoreAvailable) {
            $response = Request::get(Endpoints::getAccountMediasJsonLink($id, $maxId), $this->generateHeaders($this->userSession));
            if (static::HTTP_OK !== $response->code) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
            }
            $arr = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
            if (!is_array($arr)) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
            }
            $nodes = $arr['data']['user']['edge_owner_to_timeline_media']['edges'];
            // fix - count takes longer/has more overhead
            if (!isset($nodes) || empty($nodes)) {
                return [];
            }
            foreach ($nodes as $mediaArray) {
                if ($index === $count) {
                    return $medias;
                }
                $medias[] = Media::create($mediaArray['node']);
                $index++;
            }
            if (empty($nodes) || !isset($nodes)) {
                return $medias;
            }
            $maxId = $arr['data']['user']['edge_owner_to_timeline_media']['page_info']['end_cursor'];
            $isMoreAvailable = $arr['data']['user']['edge_owner_to_timeline_media']['page_info']['has_next_page'];
        }
        return $medias;
    }
    
    /**
     * @param string $username
     * @param int $count
     *
     * @return Media[]
     * @throws InstagramException
     */
    public function getMediasFromFeed($username, $count = 20)
    {
        $medias = [];
        $index = 0;
        $response = Request::get(Endpoints::getAccountJsonLink($username), $this->generateHeaders($this->userSession));
        if (static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }

        $userArray = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
        if (!isset($userArray['graphql']['user'])) {
            throw new InstagramNotFoundException('Account with this username does not exist', 404);
        }

        $nodes = $userArray['graphql']['user']['edge_owner_to_timeline_media']['edges'];

        if (!isset($nodes) || empty($nodes)) {
            return [];
        }

        foreach ($nodes as $mediaArray) {
            if ($index === $count) {
                return $medias;
            }
            $medias[] = Media::create($mediaArray['node']);
            $index++;
        }

        return $medias;
    }    
    
    /**
     * @param $mediaId
     *
     * @return Media
     */
    public function getMediaById($mediaId)
    {
        $mediaLink = Media::getLinkFromId($mediaId);
        return $this->getMediaByUrl($mediaLink);
    }

    /**
     * @param string $mediaUrl
     *
     * @return Media
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getMediaByUrl($mediaUrl)
    {
        if (filter_var($mediaUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Malformed media url');
        }
        $response = Request::get(rtrim($mediaUrl, '/') . '/?__a=1', $this->generateHeaders($this->userSession));
        // use a raw constant in the code is not a good idea!!
        //if ($response->code === 404) {
        if (static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException('Media with given code does not exist or account is private.');
        }
        // use a raw constant in the code is not a good idea!!
        //if ($response->code !== 200) {
        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }
        $mediaArray = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
        if (!isset($mediaArray['graphql']['shortcode_media'])) {
            throw new InstagramException('Media with this code does not exist');
        }
        return Media::create($mediaArray['graphql']['shortcode_media']);
    }

    /**
     * @param string $mediaCode (for example BHaRdodBouH)
     *
     * @return Media
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */

    public function getMediaByCode($mediaCode)
    {
        $url = Endpoints::getMediaPageLink($mediaCode);
        return $this->getMediaByUrl($url);

    }

    /**
     * @param string $username
     * @param string $maxId
     *
     * @return array
     * @throws InstagramException
     */
    public function getPaginateMedias($username, $maxId = '')
    {
        $account = $this->getAccount($username);
        $hasNextPage = true;
        $medias = [];

        $toReturn = [
            'medias' => $medias,
            'maxId' => $maxId,
            'hasNextPage' => $hasNextPage,
        ];

        $response = Request::get(Endpoints::getAccountMediasJsonLink($account->getId(), $maxId),
            $this->generateHeaders($this->userSession));

        // use a raw constant in the code is not a good idea!!
        //if ($response->code !== 200) {
        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }

        $arr = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);

        if (!is_array($arr)) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }
        $nodes = $arr['data']['user']['edge_owner_to_timeline_media']['edges'];

        //if (count($arr['items']) === 0) {
        // I generally use empty. Im not sure why people would use count really - If the array is large then count takes longer/has more overhead.
        // If you simply need to know whether or not the array is empty then use empty.
        if (empty($nodes)) {
            return $toReturn;
        }

        foreach ($nodes as $mediaArray) {
            $medias[] = Media::create($mediaArray['node']);
        }

        $maxId = $arr['data']['user']['edge_owner_to_timeline_media']['page_info']['end_cursor'];
        $hasNextPage = $arr['data']['user']['edge_owner_to_timeline_media']['page_info']['has_next_page'];

        $toReturn = [
            'medias' => $medias,
            'maxId' => $maxId,
            'hasNextPage' => $hasNextPage,
        ];

        return $toReturn;
    }

    /**
     * @param      $mediaId
     * @param int $count
     * @param null $maxId
     *
     * @return Comment[]
     */
    public function getMediaCommentsById($mediaId, $count = 10, $maxId = null)
    {
        $code = Media::getCodeFromId($mediaId);
        return static::getMediaCommentsByCode($code, $count, $maxId);
    }

    /**
     * @param      $code
     * @param int $count
     * @param null $maxId
     *
     * @return Comment[]
     * @throws InstagramException
     */
    public function getMediaCommentsByCode($code, $count = 10, $maxId = null)
    {
        $remain = $count;
        $comments = [];
        $index = 0;
        $hasPrevious = true;
        while ($hasPrevious && $index < $count) {
            if ($remain > static::MAX_COMMENTS_PER_REQUEST) {
                $numberOfCommentsToRetreive = static::MAX_COMMENTS_PER_REQUEST;
                $remain -= static::MAX_COMMENTS_PER_REQUEST;
                $index += static::MAX_COMMENTS_PER_REQUEST;
            } else {
                $numberOfCommentsToRetreive = $remain;
                $index += $remain;
                $remain = 0;
            }
            if (!isset($maxId)) {
                $maxId = '';

            }
            $commentsUrl = Endpoints::getCommentsBeforeCommentIdByCode($code, $numberOfCommentsToRetreive, $maxId);
            $response = Request::get($commentsUrl, $this->generateHeaders($this->userSession));
            // use a raw constant in the code is not a good idea!!
            //if ($response->code !== 200) {
            if (static::HTTP_OK !== $response->code) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
            }
            $cookies = static::parseCookies($response->headers['Set-Cookie']);
            $this->userSession['csrftoken'] = $cookies['csrftoken'];
            $jsonResponse = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
            $nodes = $jsonResponse['data']['shortcode_media']['edge_media_to_comment']['edges'];
            foreach ($nodes as $commentArray) {
                $comments[] = Comment::create($commentArray['node']);
            }
            $hasPrevious = $jsonResponse['data']['shortcode_media']['edge_media_to_comment']['page_info']['has_next_page'];
            $numberOfComments = $jsonResponse['data']['shortcode_media']['edge_media_to_comment']['count'];
            if ($count > $numberOfComments) {
                $count = $numberOfComments;
            }
            if (sizeof($nodes) == 0) {
                return $comments;
            }
            $maxId = $jsonResponse['data']['shortcode_media']['edge_media_to_comment']['page_info']['end_cursor'];
        }
        return $comments;
    }

    /**
     * We work only on https in this case if we have same cookies on Secure and not - we will choice Secure cookie
     *
     * @param string $rawCookies
     *
     * @return array
     */
    private static function parseCookies($rawCookies)
    {
        if (!is_array($rawCookies)) {
            $rawCookies = [$rawCookies];
        }

        $not_secure_cookies = [];
        $secure_cookies = [];

        foreach ($rawCookies as $cookie) {
            $cookie_array = 'not_secure_cookies';
            $cookie_parts = explode(';', $cookie);
            foreach ($cookie_parts as $cookie_part) {
                if (trim($cookie_part) == 'Secure') {
                    $cookie_array = 'secure_cookies';
                    break;
                }
            }
            $value = array_shift($cookie_parts);
            $parts = explode('=', $value);
            if (sizeof($parts) >= 2 && !is_null($parts[1])) {
                ${$cookie_array}[$parts[0]] = $parts[1];
            }
        }

        $cookies = $secure_cookies + $not_secure_cookies;
        return $cookies;
    }

    /**
     * @param      $code
     * @param int $count
     * @param null $maxId
     *
     * @return array
     * @throws InstagramException
     */
    public function getMediaLikesByCode($code, $count = 10, $maxId = null)
    {
        $remain = $count;
        $likes = [];
        $index = 0;
        $hasPrevious = true;
        while ($hasPrevious && $index < $count) {
            if ($remain > self::MAX_LIKES_PER_REQUEST) {
                $numberOfLikesToRetreive = self::MAX_LIKES_PER_REQUEST;
                $remain -= self::MAX_LIKES_PER_REQUEST;
                $index += self::MAX_LIKES_PER_REQUEST;
            } else {
                $numberOfLikesToRetreive = $remain;
                $index += $remain;
                $remain = 0;
            }
            if (!isset($maxId)) {
                $maxId = '';

            }
            $commentsUrl = Endpoints::getLastLikesByCode($code, $numberOfLikesToRetreive, $maxId);
            $response = Request::get($commentsUrl, $this->generateHeaders($this->userSession));
            if ($response->code !== 200) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . $response->body . ' Something went wrong. Please report issue.');
            }
            $cookies = self::parseCookies($response->headers['Set-Cookie']);
            $this->userSession['csrftoken'] = $cookies['csrftoken'];
            $jsonResponse = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);

            $nodes = $jsonResponse['data']['shortcode_media']['edge_liked_by']['edges'];

            foreach ($nodes as $likesArray) {
                $likes[] = Like::create($likesArray['node']);
            }

            $hasPrevious = $jsonResponse['data']['shortcode_media']['edge_liked_by']['page_info']['has_next_page'];
            $numberOfLikes = $jsonResponse['data']['shortcode_media']['edge_liked_by']['count'];
            if ($count > $numberOfLikes) {
                $count = $numberOfLikes;
            }
            if (sizeof($nodes) == 0) {
                return $likes;
            }
            $maxId = $jsonResponse['data']['shortcode_media']['edge_liked_by']['page_info']['end_cursor'];
        }

        return $likes;
    }

    /**
     * @param string $id
     *
     * @return Account
     * @throws InstagramException
     * @throws \InvalidArgumentException
     */
    public function getAccountById($id)
    {
        $username = $this->getUsernameById($id);
        return $this->getAccount($username);
    }

    /**
     * @param string $id
     *
     * @return string
     * @throws InstagramException
     * @throws \InvalidArgumentException
     */
    public function getUsernameById($id)
    {
        // Use the follow page to get the account. The follow url will redirect to the home page for the user,
        // which has the username embedded in the url.

        if (!is_numeric($id)) {
            throw new \InvalidArgumentException('User id must be integer or integer wrapped in string');
        }

        $url = Endpoints::getFollowUrl($id);

        // Cut a request by disabling redirects.
        Request::curlOpt(CURLOPT_FOLLOWLOCATION, FALSE);
        $response = Request::get($url, $this->generateHeaders($this->userSession));
        Request::curlOpt(CURLOPT_FOLLOWLOCATION, TRUE);

        if ($response->code === 400) {
            throw new InstagramException('Account with this id does not exist.');
        }

        if ($response->code !== 302) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->raw_body) . ' Something went wrong. Please report issue.');
        }

        $cookies = static::parseCookies($response->headers['Set-Cookie']);
        $this->userSession['csrftoken'] = $cookies['csrftoken'];

        // Get the username from the response url.
        $responseUrl = $response->headers['Location'];
        $urlParts = explode('/', rtrim($responseUrl, '/'));
        $username = end($urlParts);

        return $username;
    }

    /**
     * @param string $tag
     * @param int $count
     * @param string $maxId
     * @param string $minTimestamp
     *
     * @return Media[]
     * @throws InstagramException
     */
    public function getMediasByTag($tag, $count = 30, $maxId = '', $minTimestamp = null)
    {
    	$tryNumber = 1;
        $index = 0;
        $medias = [];
        $mediaIds = [];
        $hasNextPage = true;
        while ($index < $count && $hasNextPage) {
        	
        	// logic #1
        	if($tryNumber === 1) {
        		$variables = json_encode([
        			'tag_name' => (string) $tag,
        			'first' => (string) 30,
        			'after' => (string) $maxId
        		]);
        		$response = Request::get(Endpoints::getMediasJsonByTagLinkByHash($variables), $this->generateHeaders($this->userSession));
        		$arr = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
        		if ((static::HTTP_OK !== $response->code OR !is_array($arr)) AND !empty($this->userSession)) {
        			if(static::$debug) echo 'Iteration 1. Try endpoint 2 - MEDIA_JSON_BY_TAG_BY_QUERY<br />';
        			$response = Request::get(Endpoints::getMediasJsonByTagLinkByQuery($tag, $count, $maxId), $this->generateHeaders($this->userSession));
        			$arr = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
        			$tryNumber = 2;
        		}
        		if (static::HTTP_OK !== $response->code OR !is_array($arr)) {
        			if(static::$debug) echo 'Iteration 1. Try endpoint 3 - MEDIA_JSON_BY_TAG<br />';
        			$response = Request::get(Endpoints::getMediasJsonByTagLink($tag, $maxId), $this->generateHeaders($this->userSession));
        			$arr = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
        			$tryNumber = 3;
        		}
        		if (static::HTTP_OK !== $response->code OR !is_array($arr)) {
        			if(static::$debug) echo 'Iteration 1. Try parser<br />';
        			return $this->getMediasByTagParser($tag);
        		}
        	}
        	// logic #2
        	elseif($tryNumber === 2) {
        		$response = Request::get(Endpoints::getMediasJsonByTagLinkByQuery($tag, $count, $maxId), $this->generateHeaders($this->userSession));
        		$arr = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
        		if (static::HTTP_OK !== $response->code OR !is_array($arr)) {
        			if(static::$debug) echo 'Iteration 2. Try endpoint 3 - MEDIA_JSON_BY_TAG<br />';
        			$response = Request::get(Endpoints::getMediasJsonByTagLink($tag, $maxId), $this->generateHeaders($this->userSession));
        			$arr = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
        			$tryNumber = 3;
        		}
        		if (static::HTTP_OK !== $response->code OR !is_array($arr)) {
        			if(static::$debug) echo 'Iteration 2. Try parser<br />';
        			return $this->getMediasByTagParser($tag);
        		}
        	}
        	// logic #3
        	elseif($tryNumber === 3) {
        		$response = Request::get(Endpoints::getMediasJsonByTagLink($tag, $maxId), $this->generateHeaders($this->userSession));
        		$arr = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
        		if (static::HTTP_OK !== $response->code OR !is_array($arr)) {
        			if(static::$debug) echo 'Iteration 3. Try parser<br />';
        			return $this->getMediasByTagParser($tag);
        		}
        	}
            $cookies = static::parseCookies($response->headers['Set-Cookie']);
            $this->userSession['csrftoken'] = $cookies['csrftoken'];
            if (!is_array($arr)) {
                throw new InstagramException('Response decoding failed. Returned data corrupted or this library outdated. Please report issue');
            }
            
            if($tryNumber>=3) {
	            if (empty($arr['graphql']['hashtag']['edge_hashtag_to_media']['count'])) {
	                return [];
	            }
	            $nodes = $arr['graphql']['hashtag']['edge_hashtag_to_media']['edges'];
            }
            else {
            	if (empty($arr['data']['hashtag']['edge_hashtag_to_media']['count'])) {
            		return [];
            	}
            	$nodes = $arr['data']['hashtag']['edge_hashtag_to_media']['edges'];
            }
            foreach ($nodes as $mediaArray) {
                if ($index === $count) {
                    return $medias;
                }
                $media = Media::create($mediaArray['node']);
                if (in_array($media->getId(), $mediaIds)) {
                    return $medias;
                }
                if (isset($minTimestamp) && $media->getCreatedTime() < $minTimestamp) {
                    return $medias;
                }
                $mediaIds[] = $media->getId();
                $medias[] = $media;
                $index++;
            }
            if (empty($nodes)) {
                return $medias;
            }
            if($tryNumber>=3) {
           		$maxId = $arr['graphql']['hashtag']['edge_hashtag_to_media']['page_info']['end_cursor'];
            	$hasNextPage = $arr['graphql']['hashtag']['edge_hashtag_to_media']['page_info']['has_next_page'];
            }
            else {
            	$maxId = $arr['data']['hashtag']['edge_hashtag_to_media']['page_info']['end_cursor'];
            	$hasNextPage = $arr['data']['hashtag']['edge_hashtag_to_media']['page_info']['has_next_page'];
            }
        }
        return $medias;
    }
    public function getMediasByTagParser($tag)
    {
    	//$response = Request::get('https://instagram.com/explore/tags/'.$tag.'/',self::$headers);
    	$response = Request::get(Endpoints::getMediasJsonByTagLinkByParser($tag),self::$headers);
    	if (static::HTTP_OK !== $response->code) {
    		throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
    	}
    	if (!preg_match('#window\._sharedData\s*=\s*(.*?)\s*;\s*</script>#', $response->raw_body, $matches)) {
    		throw new InstagramException('Unexpected page data format');
    	}
    	$data = json_decode($matches[1], true);
    	if(!isset($data['entry_data']['TagPage'][0]['graphql']['hashtag'])) {
    		throw new InstagramException('Unexpected tag data format');
    	}
    	if(!isset($data['entry_data']['TagPage'][0]['graphql']['hashtag']['edge_hashtag_to_media']['edges'])) {
    		throw new InstagramException('Unexpected media data format');
    	}
    	$hashtag = $data['entry_data']['TagPage'][0]['graphql']['hashtag'];
    	$nodes = $hashtag['edge_hashtag_to_media']['edges'];
    	if(!empty($nodes)) {
    		$medias = [];
    		foreach ($nodes as $mediaArray) {
    			$medias[] = Media::create($mediaArray['node']);
    		}
    	}
    	return $medias;
    }
    /**
     * @param string $tag
     * @param string $maxId
     *
     * @return array
     * @throws InstagramException
     */
    public function getPaginateMediasByTag($tag, $maxId = '')
    {
        $hasNextPage = true;
        $medias = [];

        $toReturn = [
            'medias' => $medias,
            'maxId' => $maxId,
            'hasNextPage' => $hasNextPage,
        ];

        $response = Request::get(Endpoints::getMediasJsonByTagLink($tag, $maxId),
            $this->generateHeaders($this->userSession));

        if ($response->code !== 200) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }

        $cookies = static::parseCookies($response->headers['Set-Cookie']);
        $this->userSession['csrftoken'] = $cookies['csrftoken'];

        $arr = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);

        if (!is_array($arr)) {
            throw new InstagramException('Response decoding failed. Returned data corrupted or this library outdated. Please report issue');
        }

        if (empty($arr['graphql']['hashtag']['edge_hashtag_to_media']['count'])) {
            return $toReturn;
        }

        $nodes = $arr['graphql']['hashtag']['edge_hashtag_to_media']['edges'];

        if (empty($nodes)) {
            return $toReturn;
        }

        foreach ($nodes as $mediaArray) {
            $medias[] = Media::create($mediaArray['node']);
        }

        $maxId = $arr['graphql']['hashtag']['edge_hashtag_to_media']['page_info']['end_cursor'];
        $hasNextPage = $arr['graphql']['hashtag']['edge_hashtag_to_media']['page_info']['has_next_page'];
        $count = $arr['graphql']['hashtag']['edge_hashtag_to_media']['count'];

        $toReturn = [
            'medias' => $medias,
            'count' => $count,
            'maxId' => $maxId,
            'hasNextPage' => $hasNextPage,
        ];

        return $toReturn;
    }

    /**
     * @param $tagName
     *
     * @return Media[]
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getCurrentTopMediasByTagName($tagName)
    {
        $response = Request::get(Endpoints::getMediasJsonByTagLink($tagName, ''),
            $this->generateHeaders($this->userSession));
        if ($response->code === 404) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        if ($response->code !== 200) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }
        $cookies = static::parseCookies($response->headers['Set-Cookie']);
        $this->userSession['csrftoken'] = $cookies['csrftoken'];
        $jsonResponse = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
        $medias = [];
        $nodes = (array)@$jsonResponse['graphql']['hashtag']['edge_hashtag_to_media']['edges'];
        foreach ($nodes as $mediaArray) {
            $medias[] = Media::create($mediaArray['node']);
        }
        return $medias;
    }

    /**
     * @param $facebookLocationId
     *
     * @return Media[]
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getCurrentTopMediasByLocationId($facebookLocationId)
    {
        $response = Request::get(Endpoints::getMediasJsonByLocationIdLink($facebookLocationId),
            $this->generateHeaders($this->userSession));
        if ($response->code === 404) {
            throw new InstagramNotFoundException('Location with this id doesn\'t exist');
        }
        if ($response->code !== 200) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }
        $cookies = static::parseCookies($response->headers['Set-Cookie']);
        $this->userSession['csrftoken'] = $cookies['csrftoken'];
        $jsonResponse = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
        $nodes = $jsonResponse['location']['top_posts']['nodes'];
        $medias = [];
        foreach ($nodes as $mediaArray) {
            $medias[] = Media::create($mediaArray);
        }
        return $medias;
    }

    /**
     * @param string $facebookLocationId
     * @param int $quantity
     * @param string $offset
     *
     * @return Media[]
     * @throws InstagramException
     */
    public function getMediasByLocationId($facebookLocationId, $quantity = 12, $offset = '')
    {
        $index = 0;
        $medias = [];
        $hasNext = true;
        while ($index < $quantity && $hasNext) {
            $response = Request::get(Endpoints::getMediasJsonByLocationIdLink($facebookLocationId, $offset),
                $this->generateHeaders($this->userSession));
            if ($response->code !== 200) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
            }
            $cookies = static::parseCookies($response->headers['Set-Cookie']);
            $this->userSession['csrftoken'] = $cookies['csrftoken'];
            $arr = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
            $nodes = $arr['location']['media']['nodes'];
            foreach ($nodes as $mediaArray) {
                if ($index === $quantity) {
                    return $medias;
                }
                $medias[] = Media::create($mediaArray);
                $index++;
            }
            if (empty($nodes)) {
                return $medias;
            }
            $hasNext = $arr['location']['media']['page_info']['has_next_page'];
            $offset = $arr['location']['media']['page_info']['end_cursor'];
        }
        return $medias;
    }

    /**
     * @param string $facebookLocationId
     *
     * @return Location
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getLocationById($facebookLocationId)
    {
        $response = Request::get(Endpoints::getMediasJsonByLocationIdLink($facebookLocationId),
            $this->generateHeaders($this->userSession));
        if ($response->code === 404) {
            throw new InstagramNotFoundException('Location with this id doesn\'t exist');
        }
        if ($response->code !== 200) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }
        $cookies = static::parseCookies($response->headers['Set-Cookie']);
        $this->userSession['csrftoken'] = $cookies['csrftoken'];
        $jsonResponse = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
        return Location::create($jsonResponse['location']);
    }

    /**
     * @param string $accountId Account id of the profile to query
     * @param int $count Total followers to retrieve
     * @param int $pageSize Internal page size for pagination
     * @param bool $delayed Use random delay between requests to mimic browser behaviour
     *
     * @return array
     * @throws InstagramException
     */
    public function getFollowers($accountId, $count = 20, $pageSize = 20, $delayed = true)
    {
        if ($delayed) {
            set_time_limit($this->pagingTimeLimitSec);
        }

        $index = 0;
        $accounts = [];
        $endCursor = '';

        if ($count < $pageSize) {
            throw new InstagramException('Count must be greater than or equal to page size.');
        }

        while (true) {
            $response = Request::get(Endpoints::getFollowersJsonLink($accountId, $pageSize, $endCursor),
                $this->generateHeaders($this->userSession));
            if ($response->code !== 200) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
            }

            $jsonResponse = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);

            if ($jsonResponse['data']['user']['edge_followed_by']['count'] === 0) {
                return $accounts;
            }

            $edgesArray = $jsonResponse['data']['user']['edge_followed_by']['edges'];
            if (count($edgesArray) === 0) {
                throw new InstagramException('Failed to get followers of account id ' . $accountId . '. The account is private.');
            }

            foreach ($edgesArray as $edge) {
                $accounts[] = $edge['node'];
                $index++;
                if ($index >= $count) {
                    break 2;
                }
            }

            $pageInfo = $jsonResponse['data']['user']['edge_followed_by']['page_info'];
            if ($pageInfo['has_next_page']) {
                $endCursor = $pageInfo['end_cursor'];
            } else {
                break;
            }

            if ($delayed) {
                // Random wait between 1 and 3 sec to mimic browser
                $microsec = rand($this->pagingDelayMinimumMicrosec, $this->pagingDelayMaximumMicrosec);
                usleep($microsec);
            }
        }
        return $accounts;
    }

    /**
     * @param string $accountId Account id of the profile to query
     * @param int $count Total followed accounts to retrieve
     * @param int $pageSize Internal page size for pagination
     * @param bool $delayed Use random delay between requests to mimic browser behaviour
     *
     * @return array
     * @throws InstagramException
     */
    public function getFollowing($accountId, $count = 20, $pageSize = 20, $delayed = true)
    {
        if ($delayed) {
            set_time_limit($this->pagingTimeLimitSec);
        }

        $index = 0;
        $accounts = [];
        $endCursor = '';

        if ($count < $pageSize) {
            throw new InstagramException('Count must be greater than or equal to page size.');
        }

        while (true) {
            $response = Request::get(Endpoints::getFollowingJsonLink($accountId, $pageSize, $endCursor),
                $this->generateHeaders($this->userSession));
            if ($response->code !== 200) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
            }

            $jsonResponse = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);

            if ($jsonResponse['data']['user']['edge_follow']['count'] === 0) {
                return $accounts;
            }

            $edgesArray = $jsonResponse['data']['user']['edge_follow']['edges'];
            if (count($edgesArray) === 0) {
                throw new InstagramException('Failed to get followers of account id ' . $accountId . '. The account is private.');
            }

            foreach ($edgesArray as $edge) {
                $accounts[] = $edge['node'];
                $index++;
                if ($index >= $count) {
                    break 2;
                }
            }

            $pageInfo = $jsonResponse['data']['user']['edge_follow']['page_info'];
            if ($pageInfo['has_next_page']) {
                $endCursor = $pageInfo['end_cursor'];
            } else {
                break;
            }

            if ($delayed) {
                // Random wait between 1 and 3 sec to mimic browser
                $microsec = rand($this->pagingDelayMinimumMicrosec, $this->pagingDelayMaximumMicrosec);
                usleep($microsec);
            }
        }
        return $accounts;
    }

    /**
     * @param array $reel_ids - array of instagram user ids
     * @return array
     * @throws InstagramException
     */
    public function getStories($reel_ids = null)
    {
        $variables = ['precomposed_overlay' => false, 'reel_ids' => []];
        if (empty($reel_ids)) {
            $response = Request::get(Endpoints::getUserStoriesLink(),
                $this->generateHeaders($this->userSession));

            if ($response->code !== 200) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
            }

            $jsonResponse = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);
            if (empty($jsonResponse['data']['user']['feed_reels_tray']['edge_reels_tray_to_reel']['edges'])) {
                return [];
            }

            foreach ($jsonResponse['data']['user']['feed_reels_tray']['edge_reels_tray_to_reel']['edges'] as $edge) {
                $variables['reel_ids'][] = $edge['node']['id'];
            }
        } else {
            $variables['reel_ids'] = $reel_ids;
        }

        $response = Request::get(Endpoints::getStoriesLink($variables),
            $this->generateHeaders($this->userSession));

        if ($response->code !== 200) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }

        $jsonResponse = json_decode($response->raw_body, true, 512, JSON_BIGINT_AS_STRING);

        if (empty($jsonResponse['data']['reels_media'])) {
            return [];
        }

        $stories = [];
        foreach ($jsonResponse['data']['reels_media'] as $user) {
            $UserStories = UserStories::create();
            $UserStories->setOwner(Account::create($user['user']));
            foreach ($user['items'] as $item) {
                $UserStories->addStory(Story::create($item));
            }
            $stories[] = $UserStories;
        }
        return $stories;
    }

    /**
     * @param bool $force
     * @param bool $support_two_step_verification
     *
     * $support_two_step_verification true works only in cli mode - just run login in cli mode - save cookie to file and use in any mode
     *
     * @throws InstagramAuthException
     * @throws InstagramException
     *
     * @return array
     */
    public function login($force = false, $support_two_step_verification = false)
    {
        if ($this->sessionUsername == null || $this->sessionPassword == null) {
            throw new InstagramAuthException("User credentials not provided");
        }
        $session = $_SESSION['inWidgetCacheManager'];
        if ($force || !$this->isLoggedIn($session)) {
            $response = Request::get(Endpoints::BASE_URL);
            if ($response->code !== 200) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
            }
            //$cookies = static::parseCookies($response->headers['Set-Cookie']);
            //$csrfToken = $cookies['csrftoken'];
            preg_match('/"csrf_token":"(.*?)"/', $response->body, $match);
            if(isset($match[1])) {
            	$csrfToken = $match[1];
            }
            $cookies = static::parseCookies($response->headers['Set-Cookie']);
            $mid = $cookies['mid'];
            $headers = ['cookie' => "csrftoken=$csrfToken; mid=$mid;",
                'referer' => Endpoints::BASE_URL . '/',
                'x-csrftoken' => $csrfToken,
            ];
            $response = Request::post(Endpoints::LOGIN_URL, $headers,
                ['username' => $this->sessionUsername, 'password' => $this->sessionPassword]);

            if ($response->code !== 200) {
                if ($response->code === 400 && isset($response->body->message) && $response->body->message == 'checkpoint_required') {
                    if($support_two_step_verification) {
                        $response = $this->verifyTwoStep($response, $cookies);
                    } else {
                        throw new InstagramAuthException('Checkpoint required. Disable two step verification or try to login in browser first');
                    }
                } elseif ((is_string($response->code) || is_numeric($response->code)) && is_string($response->body)) {
                    throw new InstagramAuthException('Response code is ' . $response->code . '. Body: ' . $response->body . ' Something went wrong. Please report issue.');
                } else {
                    throw new InstagramAuthException('Something went wrong. Please report issue.');
                }
            }

            if (is_object($response->body)) {
                if (!$response->body->authenticated) {
                    throw new InstagramAuthException('Invalid user login or password');
                }
            }

            $cookies = static::parseCookies($response->headers['Set-Cookie']);
            $cookies['mid'] = $mid;
            $_SESSION['inWidgetCacheManager'] = $cookies;
            $this->userSession = $cookies;
        } else {
            $this->userSession = $session;
        }

        return $this->generateHeaders($this->userSession);
    }

    /**
     * @param $session
     *
     * @return bool
     */
    public function isLoggedIn($session)
    {
        if (is_null($session) || !isset($session['sessionid'])) {
            return false;
        }
        $sessionId = $session['sessionid'];
        $csrfToken = $session['csrftoken'];
        $headers = ['cookie' => "csrftoken=$csrfToken; sessionid=$sessionId;",
            'referer' => Endpoints::BASE_URL . '/',
            'x-csrftoken' => $csrfToken,
        ];
        $response = Request::get(Endpoints::BASE_URL, $headers);
        if ($response->code !== 200) {
            return false;
        }
        $cookies = static::parseCookies($response->headers['Set-Cookie']);
        if (!isset($cookies['ds_user_id'])) {
            return false;
        }
        return true;
    }

    private function verifyTwoStep($response, $cookies)
    {
        $new_cookies = static::parseCookies($response->headers['Set-Cookie']);
        $cookies = array_merge($cookies, $new_cookies);
        $cookie_string = '';
        foreach ($cookies as $name => $value) {
            $cookie_string .= $name . "=" . $value . "; ";
        }
        $headers = [
            'cookie' => $cookie_string,
            'referer' => Endpoints::LOGIN_URL,
            'x-csrftoken' => $cookies['csrftoken']
        ];

        $url = Endpoints::BASE_URL . $response->body->checkpoint_url;
        $response = Request::get($url, $headers);
        if (preg_match('/window._sharedData\s\=\s(.*?)\;<\/script>/', $response->raw_body, $matches)) {
            $data = json_decode($matches[1], true, 512, JSON_BIGINT_AS_STRING);
            if (!empty($data['entry_data']['Challenge'][0]['extraData']['content'][3]['fields'][0]['values'])) {
                $choices = $data['entry_data']['Challenge'][0]['extraData']['content'][3]['fields'][0]['values'];
            } elseif (!empty($data['entry_data']['Challenge'][0]['fields'])) {
                $fields = $data['entry_data']['Challenge'][0]['fields'];
                if (!empty($fields['email'])) {
                    $choices[] = ['label' => 'Email: ' . $fields['email'], 'value' => 1];
                }
                if (!empty($fields['phone_number'])) {
                    $choices[] = ['label' => 'Phone: ' . $fields['phone_number'], 'value' => 0];
                }
            }

            if (!empty($choices)) {
                if (count($choices) > 1) {
                    $possible_values = [];
                    print "Select where to send security code\n";
                    foreach ($choices as $choice) {
                        print $choice['label'] . " - " . $choice['value'] . "\n";
                        $possible_values[$choice['value']] = true;
                    }

                    $selected_choice = null;
                    while (empty($possible_values[$selected_choice])) {
                        if ($selected_choice) {
                            print "Wrong choice. Try again\n";
                        }
                        print "Your choice: ";
                        $selected_choice = trim(fgets(STDIN));
                    }
                } else {
                    print "Message with security code sent to: " . $choices[0]['label'] . "\n";
                    $selected_choice = $choices[0]['value'];
                }
                $response = Request::post($url, $headers, ['choice' => $selected_choice]);
            }
        }

        if (!preg_match('/name="security_code"/', $response->raw_body, $matches)) {
            throw new InstagramAuthException('Something went wrong when try two step verification. Please report issue.');
        }

        $security_code = null;
        while (strlen($security_code) != 6 && !is_int($security_code)) {
            if ($security_code) {
                print "Wrong security code\n";
            }
            print "Enter security code: ";
            $security_code = trim(fgets(STDIN));
        }
        $post_data = [
            'csrfmiddlewaretoken' => $cookies['csrftoken'],
            'verify' => 'Verify Account',
            'security_code' => $security_code,
        ];

        $response = Request::post($url, $headers, $post_data);
        if ($response->code !== 200) {
            throw new InstagramAuthException('Something went wrong when try two step verification and enter security code. Please report issue.');
        }

        return $response;
    }

    /**
     *
     */
    public function saveSession()
    {
        $cachedString = static::$instanceCache->getItem($this->sessionUsername);
        $cachedString->set($this->userSession);
    }
}
