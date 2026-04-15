<?php
class BookeroSettingsPage
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
        add_action( 'admin_init', array( $this, 'page_init' ) );
    }

    /**
     * Options page callback
     */
    public function create_admin_page()
    {
        // Set class property
        $this->options = get_option( 'bookero_options' );
        ?>
        <div class="wrap">
            <?php
            $active = 'settings';
            include_once getPluginDir().'views/partials/tabs.php';
            ?>
            <form method="post" action="options.php">
                <?php
                // This prints out all hidden setting fields
                settings_fields( 'bookero_group' );
                do_settings_sections( 'bookero-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register and add settings
     */
    public function page_init()
    {
        register_setting(
            'bookero_group',
            'bookero_options',
            array( $this, 'sanitize' )
        );

        add_settings_section(
            'bookero_main_section',
            'Ustawienia wtyczki Bookero',
            array( $this, 'print_section_info' ),
            'bookero-settings'
        );

        add_settings_field(
            'bookero_api_key',
            'API Key',
            array( $this, 'bookero_api_key_callback' ),
            'bookero-settings',
            'bookero_main_section'
        );

        add_settings_field(
            'show_plugin',
            'Pokaż formularz',
            array( $this, 'show_plugin_callback' ),
            'bookero-settings',
            'bookero_main_section'
        );

        add_settings_field(
            'plugin_css',
            'Wczytaj CSS formularza',
            array( $this, 'plugin_css_callback' ),
            'bookero-settings',
            'bookero_main_section'
        );

        add_settings_field(
            'plugin_type',
            'Wybierz rodzaj formularza',
            array( $this, 'plugin_type_callback' ),
            'bookero-settings',
            'bookero_main_section'
        );

    }

    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input )
    {
        $new_input = $input;
        // TODO weryfikacja inputow

        return $new_input;
    }

    /**
     * Print the Section text
     */
    /**
     * Print the Section text
     */
    public function print_section_info()
    {
        echo '<strong>Wypełnij poniższe parametry:</strong> <br /><i>Jeśli chcesz pokazać formularz rezerwacji bezpośrednio w treści, wybierz rodzaj formularza Box, a następnie skorzystaj z shortcode <strong>[bookero_form]</strong> w wybranym miejscu.</i><br />';
        echo '<i>Jeśli chcesz pokazać formularz zamawiania produktów w treści, skorzystaj z shortcode <strong>[bookero_products]</strong> w wybranym miejscu.</i><br />';
        echo '<br /><strong>[WSKAZÓWKI DOT. FORMULARZA REZERWACJI]</strong> <i>Jeśli chcesz wymusić wyświetlenie konkretnej usługi lub kategorii wykorzystaj poniższe parametry dla shortcode:</i>';
        echo '<br /><i> - Jeśli chcesz wymusić wybór kategorii skorzystaj z shortcode <strong>[bookero_form category=ID_KATEGORII]</strong></i><br /><i> - Jeśli chcesz wymusić wybór usługi skorzystaj z shortcode <strong>[bookero_form service=ID_USLUGI]</strong></i>';
        echo '<br /><i> - Jeśli chcesz domyślnie zaznaczyć kategorię skorzystaj z shortcode <strong>[bookero_form select_category=ID_KATEGORII]</strong></i><br /><i> - Jeśli chcesz domyślnie zaznaczyć usługę skorzystaj z shortcode <strong>[bookero_form select_service=ID_USLUGI]</strong></i>';
        echo '<br /><i> - Jeśli chcesz wymusić wybór pracownika skorzystaj z shortcode <strong>[bookero_form worker_id=ID_PRACOWNIKA]</strong></i><br /><i> - Jeśli chcesz dodatkowo ukryć informacje o wymuszonym pracowniku, skorzystaj z shortcode <strong>[bookero_form worker_id=ID_PRACOWNIKA hide_worker=1]</strong></i>';
        echo '<br /><br /><strong>[WSKAZÓWKI DOT. FORMULARZA PRODUKTÓW]</strong> <i>Jeśli chcesz wymusić wyświetlenie konkretnych produktów wykorzystaj poniższe parametry dla shortcode:</i>';
        echo '<br /><i> - Jeśli chcesz wymusić wybór konkretnego produktu skorzystaj z shortcode <strong>[bookero_products product=ID_PRODUKTU]</strong></i><br /><i> - Jeśli chcesz pokazać konkretne produkty skorzystaj z shortcode <strong>[bookero_products filter_products=ID_PRODUKTOW_PO_PRZECINKU]</strong></i>';
        echo '<br /><i> - Jeśli chcesz ukryć konkretne produkty skorzystaj z shortcode <strong>[bookero_products hide_products=ID_PRODUKTOW_PO_PRZECINKU]</strong></i>';
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function bookero_api_key_callback()
    {
        echo '<input type="text" id="id_number" name="bookero_options[bookero_api_key]" value="'.(isset( $this->options['bookero_api_key'] ) ? esc_attr( $this->options['bookero_api_key']) : '').'" /> ';
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function show_plugin_callback()
    {
        echo '<input type="radio" name="bookero_options[show_plugin]" value="1" '.((isset($this->options['show_plugin']) && $this->options['show_plugin'] == 1) ? ' checked' : '').'> Tak ';
        echo '<input type="radio" name="bookero_options[show_plugin]" value="0" '.((isset($this->options['show_plugin']) && $this->options['show_plugin'] == 0) ? ' checked' : '').'> Nie ';
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function plugin_type_callback()
    {
        echo '<input type="radio" name="bookero_options[plugin_type]" value="1" '.((isset($this->options['plugin_type']) && $this->options['plugin_type'] == 1) ? ' checked' : '').'> Sticky box ';
        echo '<input type="radio" name="bookero_options[plugin_type]" value="2" '.((isset($this->options['plugin_type']) && $this->options['plugin_type'] == 2) ? ' checked' : '').'> Inline box ';
        echo '<input type="radio" name="bookero_options[plugin_type]" value="3" '.((isset($this->options['plugin_type']) && $this->options['plugin_type'] == 3) ? ' checked' : '').'> Weekly box ';
        echo '<input type="radio" name="bookero_options[plugin_type]" value="4" '.((isset($this->options['plugin_type']) && $this->options['plugin_type'] == 4) ? ' checked' : '').'> Monthly box ';
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function plugin_css_callback()
    {
        echo '<input type="radio" name="bookero_options[plugin_css]" value="1" '.((isset($this->options['plugin_css']) && $this->options['plugin_css'] == 1) ? ' checked' : '').'> Tak';
        echo '<input type="radio" name="bookero_options[plugin_css]" value="0" '.((isset($this->options['plugin_css']) && $this->options['plugin_css'] == 0) ? ' checked' : '').'> Nie';
    }

}