<?php
/**
 *
 * ThinkUp/webapp/plugins/geoencoder/model/class.GeoEncoderCrawler.php
 *
 * Copyright (c) 2009-2012 Ekansh Preet Singh, Mark Wilkie
 *
 * LICENSE:
 *
 * This file is part of ThinkUp (http://thinkupapp.com).
 *
 * ThinkUp is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any
 * later version.
 *
 * ThinkUp is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more
 * details.
 *
 * You should have received a copy of the GNU General Public License along with ThinkUp.  If not, see
 * <http://www.gnu.org/licenses/>.
 */
/**
 * GeoEncoder Crawler
 *
 * The GeoEncoder crawler retrieves geolocation information for a post.
 *
 * @license http://www.gnu.org/licenses/gpl.html
 * @copyright 2009-2012 Ekansh Preet Singh, Mark Wilkie
 * @author Ekansh Preet Singh <ekanshpreet[at]gmail[dot]com>
 * @author Mark Wilkie <mwilkie[at]gmail[dot]com>
 *
 */
class GeoEncoderCrawler {

    static $is_api_available = true;

    const SUCCESS = 1;
    const ZERO_RESULTS = 2;
    const OVER_QUERY_LIMIT = 3;
    const REQUEST_DENIED = 4;
    const INVALID_REQUEST = 5;

    /**
     * Perform Geoencoding using the data available in fields place or location
     * @var PostDAO $post_dao
     * @var array $post
     * @return bool true if successfully retrieved geo data from API; false if from DB
     */
    public function performGeoencoding($post_dao, $post) {
        if (self::$is_api_available) {
            $logger = Logger::getInstance();
            $location_dao = DAOFactory::getDAO('LocationDAO');
            $post_id = $post['post_id'];
            if ($post['place']!='') {
                $location = $post['place'];
            } else {
                $location = $post['location'];
            }
            $reply_retweet_distance = 0;
            $is_reverse_geoencoded = 0;
            $find_geodata = explode(':', $location, 2);
            if (isset($find_geodata[1])) {
                $check_geodata = explode(',', trim($find_geodata[1]), 2);
                if (isset($check_geodata[0]) && isset($check_geodata[1])) {
                    $check_geodata[0] = trim($check_geodata[0]);
                    $check_geodata[1] = trim($check_geodata[1]);
                    if (is_string($find_geodata[0]) && is_numeric($check_geodata[0]) && is_numeric($check_geodata[1])){
                        $post['geo'] = $check_geodata[0].' '.$check_geodata[1];
                        $is_reverse_geoencoded = 1;
                        return self::performReverseGeoencoding($post_dao, $post);
                    }
                }
            }
            if (!$is_reverse_geoencoded) {
                $data = $location_dao->getLocation($location);
                if (isset($data)) {
                    if ($post['in_reply_to_post_id']!=null || $post['in_retweet_of_post_id']!=null) {
                        $reply_retweet_distance = $this->getDistance($post_dao, $post, $data['latlng']);
                        if (!$reply_retweet_distance) {
                            return false;
                        }
                    }
                    $post_dao->setGeoencodedPost($post_id, $post['network'], self::SUCCESS, $data['full_name'],
                    $data['latlng'], $reply_retweet_distance);
                    $logger->logSuccess('Lat/long coordinates found in DB', __METHOD__.','.__LINE__);
                    return false;
                }
                $string = self::getDataForGeoencoding($location);
                $obj=json_decode($string);
                if ($obj->status == "OK") {
                    $geodata = $obj->results[0]->geometry->location->lat.','.$obj->results[0]->geometry->location->lng;
                    $short_location = $location;
                    $location = $obj->results[0]->formatted_address;
                    if ($post['in_reply_to_post_id']!=null || $post['in_retweet_of_post_id']!=null) {
                        $reply_retweet_distance = $this->getDistance($post_dao, $post, $geodata);
                        if (!$reply_retweet_distance) {
                            return false;
                        }
                    }
                    $post_dao->setGeoencodedPost($post_id, $post['network'], self::SUCCESS, $location, $geodata,
                    $reply_retweet_distance);
                    $logger->logSuccess('Lat/long coordinates retrieved via API', __METHOD__.','.__LINE__);
                    $vals = array (
                    'short_name'=>$short_location,
                    'full_name'=>$location,
                    'latlng'=>$geodata
                    );
                    $location_dao->addLocation($vals);
                    return true;
                } else {
                    self::failedToGeoencode($post_dao, $post_id, $post['network'], $obj->status);
                }
            }
        }
        return false;
    }

    /**
     * Perform Reverse Geoencoding using the data available in field geo
     * @var PostDAO $post_dao
     * @var array $post
     * @return bool true if successfully retrieved geo data from API; false if from DB
     */
    public function performReverseGeoencoding($post_dao, $post) {
        if (self::$is_api_available) {
            $logger = Logger::getInstance();
            $location_dao = DAOFactory::getDAO('LocationDAO');
            $post_id = $post['post_id'];
            $geodata = $post['geo'];
            $reply_retweet_distance = 0;
            $data = $location_dao->getLocation($geodata);
            if (isset($data)) {
                if ($post['in_reply_to_post_id']!=null || $post['in_retweet_of_post_id']!=null) {
                    $reply_retweet_distance = $this->getDistance($post_dao, $post, $data['latlng']);
                    if (!$reply_retweet_distance) {
                        return false;
                    }
                }
                $post_dao->setGeoencodedPost($post_id, $post['network'], self::SUCCESS, $data['full_name'],
                $data['latlng'], $reply_retweet_distance);
                $logger->logSuccess('Lat/long coordinates found in DB', __METHOD__.','.__LINE__);
                return false;
            }
            $string = self::getDataForReverseGeoencoding($geodata);
            $geodata = explode(' ', $geodata, 2);
            if (isset($geodata[0]) && isset($geodata[1])) {
                $geodata = $geodata[0].','.$geodata[1];
            }
            $obj = json_decode($string);
            if (isset($obj->status) && $obj->status == 'OK') {
                foreach ($obj->results as $p) {
                    if (isset($p->types[0])) {
                        switch($p->types[0]) {
                            case 'neighborhood':
                            case 'sublocality':
                            case 'locality':
                            case 'administrative_area_level_3':
                            case 'administrative_area_level_2':
                            case 'administrative_area_level_1':
                                $location = $p->formatted_address;
                                if ($post['in_reply_to_post_id']!=null || $post['in_retweet_of_post_id']!=null) {
                                    $reply_retweet_distance = $this->getDistance($post_dao, $post, $geodata);
                                    if (!$reply_retweet_distance) {
                                        return false;
                                    }
                                }
                                $post_dao->setGeoencodedPost($post_id, $post['network'], self::SUCCESS, $location,
                                $geodata, $reply_retweet_distance);
                                $logger->logSuccess('Lat/long coordinates retrieved via API', __METHOD__.','.__LINE__);
                                $vals = array (
                                    'short_name'=>$post['geo'],
                                    'full_name'=>$location,
                                    'latlng'=>$geodata
                                );
                                $location_dao->addLocation($vals);
                                return true;
                        }
                    }
                }
            } else {
                if (isset($obj->status)) {
                    self::failedToGeoencode($post_dao, $post_id, $post['network'], $obj->status);
                }
            }
        }
        return false;
    }

    /**
     * Method to Update post if validation of geo-location data of post results in failure
     * @param PostDAO $post_dao
     * @param int $post_id
     * @param str $network
     * @param str $is_geo_encoded
     * @return null
     */
    public function failedToGeoencode($post_dao, $post_id, $network, $is_geo_encoded) {
        switch ($is_geo_encoded) {
            case 'ZERO_RESULTS':
                $post_dao->setGeoencodedPost($post_id, $network, self::ZERO_RESULTS);
                break;
            case 'OVER_QUERY_LIMIT':
                self::$is_api_available = false;
                $post_dao->setGeoencodedPost($post_id, $network, self::OVER_QUERY_LIMIT);
                $logger = Logger::getInstance();
                $logger->logUserError('Reached Google Maps\' query limit for now.', __METHOD__.','.__LINE__);
                break;
            case 'REQUEST_DENIED':
                $post_dao->setGeoencodedPost($post_id, $network, self::REQUEST_DENIED);
                break;
            case 'INVALID_REQUEST':
                $post_dao->setGeoencodedPost($post_id, $network, self::INVALID_REQUEST);
        }
    }

    /**
     * Calculate distance between reply and initial post
     * @var str $location1
     * @var str $location2
     * @return int $distance
     */
    public function getDistanceBetweenPosts($location1, $location2) {
        $latitude = array('0' => 0, '1' => 0);
        $longitude = array('0' => 0, '1' => 0);
        $place1 = explode(',',$location1,2);
        if (is_array($place1) && count($place1) >= 2) {
            $latitude[0] = $place1[0];
            $longitude[0] = $place1[1];
        }
        $place2 = explode(',',$location2,2);
        if (is_array($place2) && count($place2) >= 2) {
            $latitude[1] = $place2[0];
            $longitude[1] = $place2[1];
        }
        if ($latitude[0] == 0 || $latitude[1] == 0 || $longitude[0] == 0 || $longitude[1] == 0) {
            return 0;
        }
        $theta = $longitude[0] - $longitude[1];
        $sine = sin(deg2rad($latitude[0])) * sin(deg2rad($latitude[1]));
        $cosine = cos(deg2rad($latitude[0])) * cos(deg2rad($latitude[1])) * cos(deg2rad($theta));
        $distance = $sine + $cosine;
        $distance = acos($distance);
        $distance = rad2deg($distance);
        $distance = $distance * 60 * 1.1515;
        $distance = $distance * 1.609344;
        return (round($distance));
    }

    /**
     * Retrieve distance between reply and initial post
     * @var PostDAO $post_dao
     * @var array $post
     * @var str $geodata
     * @return int $reply_retweet_distance
     */
    public function getDistance($post_dao, $post, $geodata) {
        $reply_retweet_distance = false;
        if ($post['in_reply_to_post_id']!=null) {
            if ($post_dao->isPostInDB($post['in_reply_to_post_id'], 'twitter')) {
                $original_post = $post_dao->getPost($post['in_reply_to_post_id'], 'twitter');
                if ($original_post->is_geo_encoded == 1) {
                    $o_post_geo = $original_post->geo;
                    $reply_retweet_distance = self::getDistanceBetweenPosts($geodata, $o_post_geo);
                } else if ($original_post->is_geo_encoded == 0) {
                    return false;
                }
            } else {
                $reply_retweet_distance = -1;
            }
        }
        if ($post['in_retweet_of_post_id']!=null) {
            if ($post_dao->isPostInDB($post['in_retweet_of_post_id'], 'twitter')) {
                $original_post = $post_dao->getPost($post['in_retweet_of_post_id'], 'twitter');
                if ($original_post->is_geo_encoded == 1) {
                    $o_post_geo = $original_post->geo;
                    $reply_retweet_distance = self::getDistanceBetweenPosts($geodata, $o_post_geo);
                } else if ($original_post->is_geo_encoded == 0) {
                    return false;
                }
            } else {
                $reply_retweet_distance = -1;
            }
        }
        return $reply_retweet_distance;
    }

    /**
     * Retrieve data for Geoencoding
     * @var string $location
     * @return string $filecontents
     */
    public function getDataForGeoencoding ($location) {
        $location = urlencode($location);
        $url = "http://maps.google.com/maps/api/geocode/json?address=".$location."&sensor=true";
        $filecontents=Utils::getURLContents($url);
        return $filecontents;
    }

    /**
     * Retrieve data for reverse geoencoding
     * @var float $latitude
     * @var float $longitude
     * @return string $filecontents
     */
    public function getDataForReverseGeoencoding($latlng) {
        $latlng = explode(' ', $latlng, 2);
        if (isset($latlng[0]) && isset($latlng[1])) {
            $url = "http://maps.google.com/maps/api/geocode/json?latlng=$latlng[0],$latlng[1]&sensor=true";
            $filecontents=Utils::getURLContents($url);
            return $filecontents;
        } else {
            return '';
        }
    }
}
