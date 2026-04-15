<?php

class Bookero{

    static $api_version = 'v1';
    static $transient_key = '__bookero_id_ts';

    static function checkApiKey($key){
        $bookero_id = get_transient(self::$transient_key);
        if(!$bookero_id){
            $response = self::Query('check-key', array('key' => $key));
            if($response !== false && isset($response->result) && $response->result == 1){
                set_transient(self::$transient_key, $response->data->bookero_id, 7 * 24 * 60 * 60);
                return $response->data->bookero_id;
            }
            return false;
        }
        else{
            return $bookero_id;
        }
    }

    static function Query($query, $params = array(), $method = 'POST'){
        $post_vars = array();
        foreach($params as $key => $value){
            $post_vars []= $key.'='.urlencode($value);
        }

        $url = 'https://www.bookero.pl/api/'.self::$api_version.'/';
        $url .= $query;

        $ch = curl_init();
        if($method == 'POST'){

            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, implode('&', $post_vars));
        }
        elseif($post_vars){
            $url .= '?'.implode('&', $post_vars);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec ($ch);

        curl_close ($ch);

        try{
            $response = json_decode($response);
            return $response;
        }
        catch(Exception $e){
            return false;
        }
    }

}