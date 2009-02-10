<?php;

require_once 'UpcomingAPI.php';

$api_host = 'upcoming.yahooapis.com/services/rest/';
$api_key = 'xxxxxxxx';
$cache_config = NULL;
$api = new UpcomingAPI($api_host,
                       $api_key,
                       $cache_config);
$result = $api->event->search(array('search_text' => 'earthday'));
foreach($result["list"] as $event) {
   echo $event["name"];
}
