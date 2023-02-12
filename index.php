<?php

/**
 * Plugin Name: Oscar EMR Wordpress Integration
 * Description: PHP Plugin to integrate OSCAR EMR with WordPress by using OAuth 1.0a for REST APIs
 * Version: 1.8.0
 * Author: Yehia Qaisy
 **/

require( plugin_dir_path(__FILE__) . '/Class/Oauth.php' );

//to enable logging
if (!function_exists('write_log')) {

    function write_log($log) {
        if (true === WP_DEBUG) {
            if (is_array($log) || is_object($log)) {
                error_log(print_r($log, true));
            } else {
                error_log($log);
            }
        }
    }

}

class oscar_emr
{
    public $info = NULL;
    public $error = NULL;

    public function __construct()
    {
        $this->init();
    }

    private function init()
    {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        add_shortcode('oscar_search', array($this, 'login_form'));

        add_action('wp_ajax_oscar_appointment_date', array($this, 'oscar_appointment_date'));
        add_action('wp_ajax_nopriv_oscar_appointment_date', array($this, 'oscar_appointment_date'));

        add_action('wp_ajax_appointment_form_add', array($this, 'appointment_form_add'));
        add_action('wp_ajax_nopriv_appointment_form_add', array($this, 'appointment_form_add'));		
		
		add_filter('plugin_action_links_'.plugin_basename(__FILE__), 'salcode_add_plugin_page_settings_link');
		function salcode_add_plugin_page_settings_link( $links ) {
			$links[] = '<a href="' . admin_url( 'options-general.php?page=oscar-emr' ) . '">' . __('Settings') . '</a>';
			return $links;
		}

    }

    public function register_settings()
    {
        register_setting('oscar_emr_plugin_options', 'oscar_emr_plugin_options', array($this, 'oscar_emr_plugin_options_validate'));
        add_settings_section('api_settings', 'API Settings', array($this, 'oscar_emr_section_text'), 'oscar_emr_plugin');

        add_settings_field('oscar_emr_setting_url', 'Oscar URL', array($this, 'oscar_emr_setting_url'), 'oscar_emr_plugin', 'api_settings');
        add_settings_field('oscar_emr_setting_client_key', 'Client Key', array($this, 'oscar_emr_setting_client_key'), 'oscar_emr_plugin', 'api_settings');
        add_settings_field('oscar_emr_setting_client_secret', 'Client Secret', array($this, 'oscar_emr_setting_client_secret'), 'oscar_emr_plugin', 'api_settings');
    }

    public function oscar_emr_plugin_options_validate($input)
    {
        $validated = array();

        $validated['url'] = $this->filter_var($input['url'], FILTER_SANITIZE_STRING);
        $validated['client_key'] = $this->filter_var($input['client_key'], FILTER_SANITIZE_STRING);
        $validated['client_secret'] = $this->filter_var($input['client_secret'], FILTER_SANITIZE_STRING);

        return $validated;
    }

    public function oscar_emr_section_text()
    {
        echo '<p>Client Credentials From OSCAR EMR</p>';
    }

    public function get_option_value($option, $key)
    {
        $options = get_option($option);
        return $options && is_array($options) && isset($options[$key]) && $options[$key] ? esc_attr($options[$key]) : NULL;
    }

    public function oscar_emr_setting_url()
    {
?>
        <input id='oscar_emr_setting_url' name='oscar_emr_plugin_options[url]' type='text' size='50' value='<?= $this->get_option_value('oscar_emr_plugin_options', 'url'); ?>' />
    <?php
    }

    public function oscar_emr_setting_client_key()
    {
    ?>
        <input id='oscar_emr_setting_client_key' name='oscar_emr_plugin_options[client_key]' type='text' value='<?= $this->get_option_value('oscar_emr_plugin_options', 'client_key'); ?>' />
    <?php
    }

    public function oscar_emr_setting_client_secret()
    {
    ?>
        <input id='oscar_emr_setting_client_secret' name='oscar_emr_plugin_options[client_secret]' type='text' value='<?= $this->get_option_value('oscar_emr_plugin_options', 'client_secret'); ?>' />
    <?php
    }

    public function admin_menu()
    {
        add_options_page(
            'Oscar EMR Configuration',
            'Oscar EMR',
            'manage_options',
            'oscar-emr',
            array($this, 'admin_page')
        );

        add_submenu_page(
            NULL,
            'Oscar EMR Initiate Page',
            'Oscar EMR',
            'manage_options',
            'oscar-emr-initiate',
            array($this, 'initiate')
        );

        add_submenu_page(
            NULL,
            'Oscar EMR Callback Page',
            'Oscar EMR',
            'manage_options',
            'oscar-emr-callback',
            array($this, 'callback')
        );
    }

      public function admin_page()
    {
        global $title;

        $url = $this->get_option_value('oscar_emr_plugin_options', 'url');
        $client_key = $this->get_option_value('oscar_emr_plugin_options', 'client_key');
        $client_secret = $this->get_option_value('oscar_emr_plugin_options', 'client_secret');
        $logged = get_option('oscar_emr_plugin_logged');
        $oauth_token = get_option('oscar_emr_plugin_oauth_token');
        $oauth_token_secret = get_option('oscar_emr_plugin_oauth_token_secret');

        ?>
        <div class="wrap">
            <h1>
                <?= $title; ?>
            </h1>

            <form action="<?= "options.php"; ?>" method="post">
                <?php
                settings_fields('oscar_emr_plugin_options');
                do_settings_sections('oscar_emr_plugin'); ?>
                <input name="submit" class="button button-primary" type="submit" value="<?php esc_attr_e('Save'); ?>"/>
            </form>

            <hr/>

            <h1>
               OAuth 1.0a Authorization
            </h1>
            <table class="form-table" role="presentation">
                <tbody>
                <tr>
                    <th scope="row">Name</th>
                    <td>
                        <input type="text" value="OSCAR REST Integration" size="50" readonly>
                    </td>
                </tr>
                <tr>
                    <th scope="row">URI</th>
                    <td>
                        <input type="text" size="50" value="<?= admin_url('options.php?page=oscar-emr-callback'); ?>"
                               readonly>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Token lifetime (seconds)</th>
                    <td>
                        <input type="text" value="2147483647" readonly>
                    </td>
                </tr>
                <?php if (!$logged) { ?>
                    <tr>
                        <th scope="row">Status</th>
                        <td>
                            <?php

                            if (!$url || !$client_key || !$client_secret) {
                                ?>
                                Missing configuration
                                <?php
                            } else {
                                ?>
                                <b>Not Authorized</b><a href="<?= admin_url('options.php?page=oscar-emr-initiate'); ?>"> <small>click here to begin</small></a>
                                <?php
                            }

                            ?>
                        </td>
                    </tr>
                <?php } else { ?>
                    <tr>
                        <th scope="row">Status</th>
                        <td>
                            <b>Authorized</b><a href="<?= admin_url('options.php?page=oscar-emr-initiate'); ?>"> <small>click here to reauthorize or cancel</small></a>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Access Token</th>
                        <td>
                            <?= $oauth_token; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Access Token Secret</th>
                        <td>
                            <?= $oauth_token_secret; ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>

        </div>
        <?php
    }

    //  OAUTH 1.0a workflow to authorize oscar and callback url
    public function initiate()
    {
        $baseUrl = $this->get_option_value('oscar_emr_plugin_options', 'url');

        if (!$baseUrl) {
            wp_redirect('options.php?page=oscar-emr');
            exit;
        }

        update_option('oscar_emr_plugin_logged', FALSE);

        $consumerKey = $this->get_option_value('oscar_emr_plugin_options', 'client_key');
        $consumerSecret = $this->get_option_value('oscar_emr_plugin_options', 'client_secret');
        $callbackUrl = admin_url('options.php?page=oscar-emr-callback');

        $oauth = new OAuthYQ($consumerKey, $consumerSecret, $baseUrl);

        $data = $oauth->initiate($callbackUrl, '/ws/oauth/initiate');
		if (!isset($data['oauth_token'])) {
            wp_redirect('options.php?page=oscar-emr');
			exit;
        }
	
     
        update_option('oscar_emr_plugin_oauth_token', $data['oauth_token']);
        update_option('oscar_emr_plugin_oauth_callback_confirmed', $data['oauth_callback_confirmed']);
        update_option('oscar_emr_plugin_oauth_token_secret', $data['oauth_token_secret']);
	      
        $url = rtrim($baseUrl, '/') . '/ws/oauth/authorize?oauth_token=' . $data['oauth_token'];
    ?>
        <script>
            window.location.href = "<?=  $url; ?>";
        </script>
    <?php
    }

    // workflow for the client requests an access token from url/ws/oauth/token
    public function callback()
    {
		
		if(!isset($_GET['oauth_verifier'])) {
			
		$new_token = "";
		$new_secret = "";
		update_option('oscar_emr_plugin_logged', false);
		
	} 
	Else 	
	{
        $oauth_verifier = $this->filter_var($_GET['oauth_verifier'], FILTER_SANITIZE_STRING);
        $oauth_token = $this->filter_var($_GET['oauth_token'], FILTER_SANITIZE_STRING);

        $baseUrl = $this->get_option_value('oscar_emr_plugin_options', 'url');
      
        $consumerKey = $this->get_option_value('oscar_emr_plugin_options', 'client_key');
        $consumerSecret = $this->get_option_value('oscar_emr_plugin_options', 'client_secret');
        $oauth_token_secret = get_option('oscar_emr_plugin_oauth_token_secret');

        $oauth = new OAuthYQ($consumerKey, $consumerSecret, $baseUrl);

        $token = $oauth->getToken('/ws/oauth/token', $oauth_token, $oauth_verifier, $oauth_token_secret);
		
		$new_token = $token['oauth_token'];
		$new_secret = $token['oauth_token_secret'];
		update_option('oscar_emr_plugin_logged', true);
	}	

        update_option('oscar_emr_plugin_oauth_token', $new_token);
        delete_option('oscar_emr_plugin_oauth_callback_confirmed');
        update_option('oscar_emr_plugin_oauth_token_secret', $new_secret);

    ?>
        <script>
            window.location.href = "<?= admin_url('options-general.php?page=oscar-emr'); ?>";
        </script>
    <?php
    }

    //Form to seach for names on OSCAR EMR
      public function login_form()
    {
        $logged = get_option('oscar_emr_plugin_logged');

        if (!$logged) {
            return '<div>Plugin configuration error. Please check plugin code for proper settings</div>';
        }

        ob_start();
    ?>
        <script>
            if (typeof jQuery == 'undefined') {
                document.write('<script src="https://code.jquery.com/jquery-1.12.4.min.js"></' + 'script>');
            }
        </script>
        <div id="appointment_form">
            <script>
                function appointment_form_login(e) {
                    e.preventDefault();

                    var ajax_url = '<?= admin_url('admin-ajax.php'); ?>';

                    jQuery('#appointment_form_msg').text("");
                    jQuery('#appointment_form_submit').attr('disabled', 'disabled');
                    jQuery('#appointment_form_submit').text('Processing...');
                    jQuery('#results').html("");
                    jQuery.post(ajax_url, {
                        action: 'oscar_appointment_date',
                        firstname: jQuery('#appointment_form_firstname').val(),
                        lastname: jQuery('#appointment_form_lastname').val(),

                    }, function(d) {
                        //console.log(d);
                        if (d.success) {
                            //alert('success');
                            d.data.content.forEach(row => {
                                jQuery('#results').append(`<br><hr><br>`);
                                for (key in row) {
                                    jQuery('#results').append(`<b>${key}:</b> ${row[key]}<br>`);
                                }
                            })

                            //jQuery('#results').html(JSON.stringify(d.data.content));
                            jQuery('#appointment_form_submit').removeAttr('disabled');
                            jQuery('#appointment_form_submit').text('Submit');
                        } else {
                            //alert('fail: ' + d);
                            jQuery('#appointment_form_msg').text('fail: ' + d);

                            jQuery('#appointment_form_submit').removeAttr('disabled');
                            jQuery('#appointment_form_submit').text('Submit');
                        }
                    }, 'json').fail(function(xhr, status, error) {
                        jQuery('#appointment_form_submit').removeAttr('disabled');
                        jQuery('#appointment_form_submit').text('Submit');
                    });

                    return false;
                }
            </script>
            <div>
                <form onsubmit="return appointment_form_login(event);">
                    <div id="appointment_form_msg" style="text-align: center;color: #ff0000;font-weight: bold;"></div>
                    <div>
                        <label for="appointment_form_firstname">
                            Fax Number
                        </label>
                        <input type="text" id="appointment_form_firstname">
                    </div>
              
                    <div>
                        <button type="submit" id="appointment_form_submit">
                            Submit
                        </button>
                    </div>
                </form>
                <div id="results" style="padding-top: 1rem;padding-bottom: 1rem;"></div>
            </div>
        </div>
<?php

        $content = ob_get_contents();
        ob_end_clean();

        return $content;
    }

    /**Method for REST Calls to OSCAR EMR.
	* @param Endpoint  - demographics/search
	* @param Search by Name	**/
    public function oscar_appointment_date()
    {
        $logged = get_option('oscar_emr_plugin_logged');
        //ws/services/demographics/search
        if (!$logged) {
            echo json_encode(array(
                'success' => FALSE,
                'error' => 'Plugin configuration error. Please check plugin code for proper settings'
            ));
            wp_die();
        }

        $baseUrl = $this->get_option_value('oscar_emr_plugin_options', 'url');

        $consumerKey = $this->get_option_value('oscar_emr_plugin_options', 'client_key');
        $consumerSecret = $this->get_option_value('oscar_emr_plugin_options', 'client_secret');

        $oauth_token = get_option('oscar_emr_plugin_oauth_token');
        $oauth_token_secret = get_option('oscar_emr_plugin_oauth_token_secret');

        $oauth = new OAuthYQ($consumerKey, $consumerSecret, $baseUrl);

  		$firstName = $this->filter_var($_POST['firstname'], FILTER_SANITIZE_STRING);
                  
		$search = $oauth->call('/ws/services/pharmacies/', [
            "offset" => 0,
            "limit" => 1000
	      ], 'Content-Type: application/json' , 'GET');		
  		    
        $filtered_content = array();
        foreach($search->content as $value) {
              
          if(trim($value->fax, "-") == trim($firstName, "-")) {          
            array_push($filtered_content, $value);
          }
        } 
        $search->content = $filtered_content;
        

        echo json_encode(array(
            'success' => true,
            'data' => $search
        ));
		       
        wp_die();

        ob_start();
    } // end oscar_appointment

    private function filter_var($mixed, $filter)
    {
        return trim(filter_var($mixed, $filter));
    }
}

$oscar_emr = new oscar_emr();


   
 