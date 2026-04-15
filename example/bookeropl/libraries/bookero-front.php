<?php
class BookeroFrontPage
{
    /**
     * Holds the values to be used in the fields callbacks
     */
    private $options;

    /**
     * Start up
     */
    public function __construct()
    {
        add_action( 'wp_footer', array( $this, 'show_plugin' ) );
        add_shortcode( 'bookero_form', array( $this, 'bookero_form' ) );
        add_shortcode( 'bookero_products', array( $this, 'bookero_products' ) );
    }

    /**
     * Show plugin
     */
    public function show_plugin()
    {
        $this->options = get_option( 'bookero_options' );

        $plugin_id = false;
        if (!empty($this->options['bookero_api_key'])){
            $plugin_id = Bookero::checkApiKey($this->options['bookero_api_key']);
        }

        if($this->options['show_plugin'] == 1 && $plugin_id !== false) {
            list($plugin_id, $container, $plugin_type, $plugin_css, $lang) = $this->get_instance_object();
            if($plugin_type == 'sticky'){
                echo $this->bookero_form([], true);
            }
            $plugin_html = $this->getPlugin();
            echo $plugin_html;
        }
    }

    /**
     * Get instance of Bookero config
     *
     * @return array
     */
    private function get_instance_object(){
        $this->options = get_option( 'bookero_options' );

        $plugin_id = false;
        if (!empty($this->options['bookero_api_key'])){
            $plugin_id = Bookero::checkApiKey($this->options['bookero_api_key']);
        }

        if(!isset($this->options['show_plugin']))
            $this->options['show_plugin'] = 1;
        if(!isset($this->options['plugin_type']))
            $this->options['plugin_type'] = 1;
        if(!isset($this->options['plugin_css']))
            $this->options['plugin_css'] = 1;
        if(!isset($this->options['plugin_html_id']))
            $this->options['plugin_html_id'] = 'bookero';

        if($this->options['show_plugin'] == 1 && $plugin_id !== false) {

            if ($this->options['plugin_type'] == 1) {
                $container = '';
                $plugin_type = 'sticky';
            } elseif ($this->options['plugin_type'] == 3) {
                $container = $this->options['plugin_html_id'];
                $plugin_type = 'full';
            } elseif ($this->options['plugin_type'] == 4) {
                $container = $this->options['plugin_html_id'];
                $plugin_type = 'calendar';
            } else {
                $container = $this->options['plugin_html_id'];
                $plugin_type = 'standard';
            }

            $plugin_css = $this->options['plugin_css'] == 1 ? 'true' : 'false';

            $wp_lang = get_locale(); // ZWRACA KOD JEZYKA NP pl_PL
            $wp_lang = explode('_', $wp_lang);
            $lang = strtolower($wp_lang[0]);
            if (!in_array($lang, array('pl', 'en', 'ru', 'de', 'it', 'cs'))) {
                $lang = 'pl';
            }
            if ($lang == 'cs') {
                $lang = 'cz';
            }

            return [
                $plugin_id, $container, $plugin_type, $plugin_css, $lang
            ];
        }
        return [
            null, null, null, null, null
        ];
    }

    /**
     * Shortcode to show Bookero booking form
     *
     * @return string
     */
    public function bookero_form($atts, $force_sticky = false){
        $atts = array_change_key_case((array)$atts, CASE_LOWER);
        $params = array();
        if(isset($atts['service']) && !isset($atts['select_service'])){
            $params['use_service_id'] = 'use_service_id: '.(int) $atts['service'];
        }
        if(isset($atts['category']) && !isset($atts['select_category'])){
            $params['use_service_category_id'] = 'use_service_category_id: '.(int) $atts['category'];
        }
        if(isset($atts['select_service']) && !isset($atts['service'])){
            $params['select_service_id'] = 'select_service_id: '.(int) $atts['select_service'];
        }
        if(isset($atts['select_category']) && !isset($atts['category'])){
            $params['select_service_category_id'] = 'select_service_category_id: '.(int) $atts['select_category'];
        }
        if(isset($atts['worker_id'])){
            $params['use_worker_id'] = 'use_worker_id: '.(int) $atts['worker_id'];
        }
        if(isset($atts['hide_worker']) && isset($atts['worker_id'])){
            $params['hide_worker_info'] = 'hide_worker_info: '.(int) $atts['hide_worker'];
        }

        $custom_config = '{}';
        if(!empty($params)){
            $custom_config = '{'.implode(', ', $params).'}';
        }

        list($plugin_id, $container, $plugin_type, $plugin_css, $lang) = $this->get_instance_object();

        if($container){
            $container = 'bookero_'.uniqid();
        }
        $html = '';
        if($plugin_type != 'sticky' || $force_sticky == true){
            $html = "<script type=\"text/javascript\">
if(bookero_config == undefined){
    var bookero_config = [];
}
var bookero_instance_config = {
    id: '" . $plugin_id . "',
    container: '" . $container . "',
    type: '" . $plugin_type . "',
    position: '',
    plugin_css: " . $plugin_css . ",
    lang: '" . $lang . "',
    custom_config: ".$custom_config."
};
bookero_config.push(bookero_instance_config);
</script>";
        }
        if($container){
            $html .= "<div id=\"".$container."\"></div>";
        }

        return $html;
    }

    /**
     * Shortcode to show Bookero products form
     *
     * @return string
     */
    public function bookero_products($atts){
        $atts = array_change_key_case((array)$atts, CASE_LOWER);
        $params = array();
        if(isset($atts['product'])){
            $params['use_product_id'] = 'use_product_id: '.(int) $atts['product'];
        }
        if(isset($atts['hide_products'])){
            $params['hidden_product_ids'] = 'hidden_product_ids: ['.$atts['hide_products'].']';
        }
        if(isset($atts['filter_products'])){
            $params['filter_products_by_id'] = 'filter_products_by_id: ['.$atts['filter_products'].']';
        }

        $custom_config = '{}';
        if(!empty($params)){
            $custom_config = '{'.implode(', ', $params).'}';
        }

        list($plugin_id, $container, $plugin_type, $plugin_css, $lang) = $this->get_instance_object();
        $container = $container.'_'.uniqid();

        $html = "<script type=\"text/javascript\">
if(bookero_config == undefined){
    var bookero_config = [];
}
var bookero_instance_config = {
    id: '" . $plugin_id . "',
    container: '" . $container . "',
    type: 'products',
    position: '',
    plugin_css: " . $plugin_css . ",
    lang: '" . $lang . "',
    custom_config: ".$custom_config."
}
bookero_config.push(bookero_instance_config);
</script>";
        if($container){
            $html .= "<div id=\"".$container."\"></div>";
        }

        return $html;
    }

    /**
     * Load Bookero library
     *
     * @return string
     */
    public function getPlugin(){
        $plugin_html = "<script type=\"text/javascript\">
              if(bookero_config != undefined && bookero_config.length >= 1){  
                  (function() {
                    var d = document, s = d.createElement('script');
                    s.src = 'https://cdn.bookero.pl/plugin/v2/js/bookero-compiled.js';
                    d.body.appendChild(s);
                  })();
              }    
			</script>";

        return $plugin_html;
    }

}