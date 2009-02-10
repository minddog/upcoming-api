<?php;
/*
 * UpcomingAPI - An up to date fully implemented api client for Upcoming.org
 *
 * Copyright(c) 2009 Adam Ballai <aballai@gmail.com>
 *
 * Example Usage
 * $api_host = 'upcoming.yahooapis.com/services/rest/';
 * $api_key = 'xxxxxxxx';
 * $cache_config['memcache_servers'] = array("localhost");
 * $cache_config['memcache_port'] = 11211;
 * $cache_config['key_prefix'] = "upcoming";
 *
 * $api = new UpcomingAPI($api_host,
                          $api_key,
                          $cache_config);
 * $result = $api->event->search(array('search_text' => 'earthday'));
 * foreach($result["list"] as $event) {
 *    echo $event["name"];
 * }
 *
 */

class UpcomingAPIError extends Exception {}

class UpcomingAPI {
    private static $curl = null;
    
    public function __construct($api_host,
                                $api_key,
                                $cache_config = NULL)
    {

        $this->event = new UpcomingAPINSWrapper($this, 'event');
        $this->auth = new UpcomingAPINSWrapper($this, 'auth');
        $this->metro = new UpcomingAPINSWrapper($this, 'metro');
        $this->state = new UpcomingAPINSWrapper($this, 'state');
        $this->country = new UpcomingAPINSWrapper($this, 'country');
        $this->venue = new UpcomingAPINSWrapper($this, 'venue');
        $this->category = new UpcomingAPINSWrapper($this, 'category');
        $this->watchlist = new UpcomingAPINSWrapper($this, 'watchlist');
        $this->user = new UpcomingAPINSWrapper($this, 'user');
        $this->group = new UpcomingAPINSWrapper($this, 'group');

        $this->api_host = $api_host;
        $this->api_key = $api_key;
        if(!empty($cache_config)) {
            $this->setup_memcache($cache_config['memcache_servers'],
                                  $cache_config['memcache_port'],
                                  $cache_config['key_prefix']);
        }
    }

    public function setup_memcache($memcache_servers, $memcache_port, $key_prefix) {
        $this->memcache = new Memcache();
        foreach ($memcache_servers as $memcache_server) {
            $this->memcache->addServer($memcache_server, $memcache_port);
        }
        $this->key_prefix = $key_prefix;
    }


    public function build_key($url, $req_per_hour=1) {
        $stamp = intval(time() * ($req_per_hour / 3600));
        return $this->key_prefix . ':' . $stamp . ':' . $url;
    }

    function fetch($url, $req_per_hour=1) {
        if(!$this->memcache) {
            return $this->perform_request($url);
        }
        
        $key = $this->build_key($url, $req_per_hour);
        echo $key."\n";
        $value = $this->memcache->get($key);
        if (!$value) {
            $value = $this->perform_request($url);
            if (!$value) return null;
            $value = json_encode($value);
            $this->memcache->set($key, $value);
        }
        if (!$value) return null;
        return json_decode($value, true);
    }

    public function perform_request($url) {
        // Send the HTTP request.
        curl_setopt(self::$curl, CURLOPT_URL, $url);
        curl_setopt(self::$curl, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec(self::$curl);

        // Throw an exception on connection failure.
        if (!$response) throw new UpcomingAPIError('Connection failed');
        
        // Deserialize the response string and store the result.
        $result = self::upcoming_decode($response);
        
        return $result;
    }
    
    public function __call($method, $args) {
        static $api_cumulative_time = 0;
        $time = microtime(true);
        
        // Initialize CURL if called for the first time.
        if (is_null(self::$curl)) {
            self::$curl = curl_init();
        }

        // Build the base URL.
        $namespace = 'event';
        if (isset($args[0])) {
            $namespace = $args[0];
            unset($args[0]);
        }

        if(isset($args[1])) {
            $args = $args[1];
        }
        $args['api_key'] = $this->api_key;
        
        $url = ('http://' . $this->api_host
                . '/?method=' . $namespace
                . '.' . $method
                . '&' . http_build_query($args));

        $result = $this->fetch($url);
        
        // If the result is a hash containing a key called 'error', assume
        // that an error occurred on the other end and throw an exception.
        if (isset($result['error'])) {
            throw new UpcomingAPIError($result['error'], $result['code']);
        } else {
            return $result['result'];
        }
    }

    function upcoming_decode($xml) {
        $error = NULL;
        $result = NULL;
        
        $doc = DOMDocument::loadXML($xml);
        $rsp = $doc->getElementsByTagName('rsp')->item(0);
        $result['stat'] = $rsp->getAttribute('stat');
        $result['count'] = $rsp->getAttribute('resultcount');
    
        if($result['stat'] == 'fail') {
            $error = $rsp->getElementsByTagName('error')->item(0);
            return array( 'error' => $error->getAttribute('msg') );
        }
    
        if(!$rsp->hasChildNodes())
            return array("result" => $result);
    
        $result["list"] = array();
        foreach($rsp->childNodes as $node) {
            if($node->nodeType == XML_ELEMENT_NODE) {
                $item = array();
                foreach($node->attributes as $n => $v) {
                    $item[$n] = $v->nodeValue;
                }
                $result["list"][] = $item;
            }
        }
    
        return array("result" => $result);
    }
}

class UpcomingAPINSWrapper {
    private $object;
    private $ns;
    
    function __construct($obj, $ns) {
        $this->object = $obj;
        $this->ns = $ns;
    }
    
    function __call($method, $args) {
        $args = array_merge(array($this->ns), $args);
        return call_user_func_array(array($this->object, $method), $args);
    }
}

?>