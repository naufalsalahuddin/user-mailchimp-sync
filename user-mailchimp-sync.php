<?php
/*
Plugin Name: User Mailchimp Sync
Description: Adds an options page in which you can add the Mailchimp API key and sync WordPress users with Mailchimp.
Version: 1.0
Author: Naufal Salahuddin
*/

class NH_User_Mailchimp_Sync {
    private $plugin_name = 'user_mailchimp_sync';
    private $user_keys = array('first_name','last_name','user_name','display_name','id','email','nick_name','website','meta_key');

    public function __construct() {
        add_action('admin_menu', array($this, 'nh_mailchimp_sync_options_menu'));
        add_action('admin_init', array($this, 'nh_mailchimp_options_init'));
        add_action('plugins_loaded', array($this, 'nh_instantiate_user_mailchimp_sync'));
        add_action('user_register', array($this, 'nh_add_user_to_mailchimp'));
        add_action('profile_update', array($this, 'nh_update_user_data_to_mailchimp'));

    }

    public function nh_mailchimp_sync_options_menu() {
        add_options_page('User Mailchimp Sync', 'User Mailchimp Sync', 'manage_options', $this->plugin_name, array($this, 'nh_mailchimp_sync_options_page'));
    }

    public function nh_mailchimp_sync_options_page() {
        ?>
        <div class="wrap">
            <h2>Custom Options</h2>
            <form method="post" action="options.php">
                <?php settings_fields($this->plugin_name . '_group'); ?>
                <?php do_settings_sections($this->plugin_name); ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function nh_mailchimp_options_init() {
        register_setting($this->plugin_name . '_group', 'nh_mailchimp_api_key', array($this, 'nh_mailchimp_api_validate'));
        register_setting($this->plugin_name . '_group', 'nh_mailchimp_list_id');
        register_setting($this->plugin_name . '_group', 'nh_mailchimp_map_fields');
        
        add_settings_section('api_settings_section', 'API Settings', array($this, 'nh_mailchimp_api_settings_section_callback'), $this->plugin_name);
        add_settings_field('api_field', 'API Key', array($this, 'nh_mailchimp_api_field_callback'), $this->plugin_name, 'api_settings_section');
        add_settings_field('list_field', 'List ID', array($this, 'nh_mailchimp_list_field_callback'), $this->plugin_name, 'api_settings_section');
        add_settings_field('user_fields', 'Map Fields', array($this, 'nh_mailchimp_repeater_field_callback'), $this->plugin_name, 'api_settings_section');
    }

    public function nh_mailchimp_api_settings_section_callback() {
        echo '<p>Enter your API key below:</p>';
    }

    public function nh_mailchimp_api_field_callback() {
        $api_key = get_option('nh_mailchimp_api_key');
        echo '<input type="text" id="nh_mailchimp_api_key" name="nh_mailchimp_api_key" value="' . esc_attr($api_key) . '" />';
    }

    public function nh_mailchimp_list_field_callback() {
        $api_key = get_option('nh_mailchimp_api_key');
        if ($api_key && $this->nh_mailchimp_remote_api_validation($api_key)) {
            // Show radio options if API key is present
    
            $response = wp_remote_request( 
                'https://' . substr($api_key,strpos($api_key,'-')+1) . '.api.mailchimp.com/3.0/lists/',
                array(
                    'method' => 'GET',
                    'headers' => array(
                        'Authorization' => 'Basic ' . base64_encode( 'user:'. $api_key )
                    ),
                )
            );
            if (is_wp_error($response)) {
                return;
            }
    
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true); 
            if (!$data || !isset($data['lists'])) {
                return;
            }
    
            $lists = $data['lists'];
            $selected = get_option('nh_mailchimp_list_id');
            foreach ($lists as $list) {
                echo '<label><input type="radio" name="nh_mailchimp_list_id" value="' . esc_attr($list['id']) . '" ' . checked($selected, $list['id'], false) . '/>' . $list['name'] . '</label><br>';
            }
        }
    }

    public function nh_mailchimp_repeater_field_callback() {
        $api_key = get_option('nh_mailchimp_api_key');
        if ($api_key && $this->nh_mailchimp_remote_api_validation($api_key)) {
            $nh_map_fields = get_option('nh_mailchimp_map_fields');
            ?>
            <div id="original_fields" style='display:none'>
                <div class="repeater-field">
                    <select class='user_key_select' name="nh_mailchimp_map_fields_duplicate[id_to_change][user_key]">
                        <?php
                            foreach($this->user_keys as $userKey){
                                ?>
                                    <option value='<?php echo $userKey;?>'><?php echo $userKey;?></option>
                                <?php
                            }
                        ?>
                    </select>
                    <input disabled type="text" name="nh_mailchimp_map_fields_none[id_to_change][user_key]" placeholder="Enter Meta Key">
                    <input type="text" name="nh_mailchimp_map_fields_duplicate[id_to_change][mailchimp_key]" placeholder="Enter Mailchimp Meta Key">
                    <button type='button' class="remove-field">Remove</button>
                </div>
            </div>
            <div id="fields-container">
                <?php
                    if($nh_map_fields){
                        // If Already Present
                        foreach($nh_map_fields as $index => $map_field){
                            ?>
                            <div class="repeater-field">
                                <select class='user_key_select' required name="nh_mailchimp_map_fields[<?php echo $index; ?>][user_key]">
                                    <?php
                                        foreach($this->user_keys as $userKey){
                                            ?>
                                                <option <?php selected($map_field['user_key'], $userKey, true); echo (!in_array($map_field['user_key'], $this->user_keys) && $userKey ==='meta_key') ? 'selected' : '' ?> value='<?php echo $userKey;?>'><?php echo $userKey;?></option>
                                            <?php
                                        }
                                    ?>
                                </select>
                               
                                <input <?php
                                    if($map_field['user_key'] === "meta_key" || !in_array($map_field['user_key'], $this->user_keys)){
                                        echo "value=".$map_field['user_key'];
                                    } else {
                                        echo "disabled";
                                    }
                                 ?>
                                type="text" name="nh_mailchimp_map_fields_none[<?php echo $index; ?>][user_key]" placeholder="Enter Meta Key">
    
                                <input required type="text" name="nh_mailchimp_map_fields[<?php echo $index; ?>][mailchimp_key]" value="<?php echo $map_field['mailchimp_key'] ?>" placeholder="Enter Mailchimp Meta Key">
                                <?php
                                    if($index != 0){
                                        echo "<button type='button' class='remove-field'>Remove</button>"; 
                                    }
                                ?>
                            </div>
                            <?php
                        }   
                    } else {
                        ?>
                            <div class="repeater-field">
                                <select class='user_key_select' required name="nh_mailchimp_map_fields[0][user_key]">
                                    <?php
                                        foreach($this->user_keys as $userKey){
                                            ?>
                                                <option value='<?php echo $userKey;?>'><?php echo $userKey;?></option>
                                            <?php
                                        }
                                    ?>
                                </select>
                                <input disabled type="text" name="nh_mailchimp_map_fields_none[0][user_key]" placeholder="Enter Meta Key">
                                <input required type="text" name="nh_mailchimp_map_fields[0][mailchimp_key]" placeholder="Enter Mailchimp Meta Key">
                            </div>
                        <?php
                    }
                ?>
            </div>
            <button type='button' id="add-field">Add Field</button>
    
    
            <script>
                jQuery(document).ready(function($) {
                    $('#add-field').click(function() {
                        var field = $('#original_fields').html().replace(/id_to_change/g, Math.random().toString(36).substring(7));
                        field = field.replace(/nh_mailchimp_map_fields_duplicate/g, 'nh_mailchimp_map_fields');
    
                        $('#fields-container').append(field);
                    });
    
                    $('#fields-container').on('click', '.remove-field', function() {
                        $(this).parent('.repeater-field').remove();
                    });
    
                    $(document).on('change', '.user_key_select', function() {
                        let input = $(this).next();
                        let nameAttribute = input.attr("name");
                        var newName;
                        if($(this).val() === 'meta_key'){
                            input.prop("disabled", false);
                            newName = nameAttribute.replace("nh_mailchimp_map_fields_none", "nh_mailchimp_map_fields");
                        } else {
                            input.prop("disabled", true);
                            newName = varnameAttribute.replace("nh_mailchimp_map_fields", "nh_mailchimp_map_fields_none");
                        }
                        input.attr("name", newName);
    
                    })
    
                });
            </script>
    
            <?php
        }
    }

    public function nh_mailchimp_api_validate($input) {
        // Perform remote validation here
        $valid = $this->nh_mailchimp_remote_api_validation($input);
        if ($valid) {
            return $input;
        } else {
            add_settings_error('nh_mailchimp_api_key', 'invalid_key', 'The API key is invalid.', 'error');
            return get_option('nh_mailchimp_api_key'); // Revert back to the previous value
        }
    }

    public function nh_mailchimp_remote_api_validation($api_key) {
        $response = wp_remote_request( 
            'https://' . substr($api_key,strpos($api_key,'-')+1) . '.api.mailchimp.com/3.0/lists/',
            array(
                'method' => 'GET',
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( 'user:'. $api_key )
                ),
            )
        );
        if (is_wp_error($response)) {
            return false;
        }
    
        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
    
        if ($status_code == 200 || $status_code == 204) {
            return true;
        }
        return false;
    }

    public function nh_mailchimp_prepare_user_fields($user_id) {
        $user_info = get_userdata($user_id);
        if($user_info){
            $id = $user_info->ID;
            $email = $user_info->user_email;
            $first_name = $user_info->first_name;
            $last_name = $user_info->last_name;
            $user_name = $user_info->user_login;
            $nick_name = $user_info->user_nicename;
            $display_name = $user_info->display_name;
            $website = $user_info->user_url;
            

            $nh_map_fields = get_option('nh_mailchimp_map_fields');
            $mailchimpData = array();
            if($nh_map_fields){
                foreach($nh_map_fields as $nh_map_field){
                    $user_key = $nh_map_field['user_key'];
                    $mailchimp_key = $nh_map_field['mailchimp_key'];
                    $user_data;
                    switch ($user_key) {
                        case 'first_name':
                            $user_data = $first_name;
                            break;
                        case 'last_name':
                            $user_data = $last_name;
                            break;
                        case 'user_name':
                            $user_data = $user_name;
                            break;
                        case 'display_name':
                            $user_data = $display_name;
                            break;
                        case 'id':
                            $user_data = $id;
                            break;
                        case 'email':
                            $user_data = $email;
                            break;
                        case 'nick_name':
                            $user_data = $nick_name;
                            break;
                        case 'website':
                            $user_data = $website;
                            break;
                        case 'meta_key':
                            $user_meta = get_user_meta($user_id, $user_key, true);
                            $user_data = $user_meta ? $user_meta : '';
                            break;
                        default:
                            $user_meta = get_user_meta($user_id, $user_key, true);
                            $user_data = $user_meta ? $user_meta : '';
                            break;
                    }
                    $mailchimpData[$mailchimp_key] = $user_data;
                }
                // print_r($mailchimpData);
                return $mailchimpData;
            }
        } else {
            return false;
        }
    }


    // Create A Contact When New User Registers
    public function nh_add_user_to_mailchimp($user_id) {
        $api_key = get_option('nh_mailchimp_api_key');
        $list_id = get_option('nh_mailchimp_list_id');
        $user_info = get_userdata($user_id);
        $user_email = $user_info->user_email;
        $mailchimpData = $this->nh_mailchimp_prepare_user_fields($user_id);
        if ($api_key && $this->nh_mailchimp_remote_api_validation($api_key) && $mailchimpData) {
            $url = 'https://' . substr($api_key,strpos($api_key,'-')+1) . '.api.mailchimp.com/3.0/lists/' . $list_id . '/members';
            $data = array(
                'email_address' => $user_email,
                'status' => 'subscribed',
                'merge_fields' => $mailchimpData,
            );

            $args = array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode('user:' . $api_key),
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($data),
                'timeout' => 10,
                'method' => 'POST'
            );
            $response = wp_remote_request($url, $args);
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                echo $error_message;
                error_log('Mailchimp API error: ' . $error_message);
            } else {
                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code == 200 || $status_code == 204) {
                    error_log('User added to Mailchimp list successfully.');
                } else {
                    error_log('Failed to add user to Mailchimp list. Status Code: ' . $status_code);
                }
                echo $status_code;
            }
        }
    }

    public function nh_update_user_data_to_mailchimp($user_id) {
        $api_key = get_option('nh_mailchimp_api_key');
        $list_id = get_option('nh_mailchimp_list_id');
        $user_info = get_userdata($user_id);
        $user_email = $user_info->user_email;
        $mailchimpData = $this->nh_mailchimp_prepare_user_fields($user_id);
        $mailchimpData = array(
            'email_address' => $user_email,
            'status_if_new' => 'subscribed',
            'merge_fields' => $mailchimpData,
        );
        // Update or create subscriber
        if ($api_key && $this->nh_mailchimp_remote_api_validation($api_key) && $mailchimpData) {
            $url = 'https://' . substr($api_key,strpos($api_key,'-')+1) . '.api.mailchimp.com/3.0/lists/' . $list_id . '/members/' . md5(strtolower($user_email));
            $args = array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode('user:' . $api_key),
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($mailchimpData),
                'timeout' => 10,
                'method' => 'PUT'
            );
            $response = wp_remote_request($url, $args);
            if (is_wp_error($response)) {
                error_log('Failed to update user data to Mailchimp: ' . $response->get_error_message());
            } else {
                $response_code = wp_remote_retrieve_response_code($response);
                if ($response_code === 200) {
                    error_log('User data updated successfully in Mailchimp.');
                } else {
                    error_log('Failed to update user data to Mailchimp. Status code: ' . $response_code);
                }
            }
        }
    }    
}

// Instantiate the class when WordPress is loaded
function nh_instantiate_user_mailchimp_sync() {
    $nh_user_mailchimp_sync = new NH_User_Mailchimp_Sync();
    // $nh_user_mailchimp_sync->nh_update_user_data_to_mailchimp(5);
}
add_action('plugins_loaded', 'nh_instantiate_user_mailchimp_sync');