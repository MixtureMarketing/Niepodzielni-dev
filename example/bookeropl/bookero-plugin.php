<?php
/*
   Plugin Name: Bookero Plugin
   Plugin URI: http://wordpress.org/extend/plugins/bookero-plugin/
   Version: 2.1
   Author: Bookero.pl
   Description: Wtyczka do wordpress, wyświetlająca formularz rezerwacji online Bookero
   Text Domain: bookero-plugin
   License: GPLv2 or later
*/

include_once('libraries/bookero.php');
include_once('libraries/bookero-panel.php');
include_once('libraries/bookero-settings.php');
include_once('libraries/bookero-front.php');


function getPluginDir() {
    return plugin_dir_path(__FILE__);
}

if( is_admin() ){
    if(isset($_POST['bookero_options']['bookero_api_key'])){
        delete_transient(Bookero::$transient_key);
    }
    $bookero_panel = new BookeroPanelPage();
}
else{
    $bookero_front_page = new BookeroFrontPage();
}