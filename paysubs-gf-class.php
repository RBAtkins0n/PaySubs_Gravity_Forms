<?php
/*
 * Copyright (c) 2022 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */
add_action( 'parse_request', array( "GFPaySubs", "paysubs_notify_handler" ) );
add_action( 'wp', array( 'GFPaySubs', 'paysubs_thankyou_page' ), 5 );
ini_set( 'display_errors', 1 );

GFForms::include_payment_addon_framework();

require_once 'paysubs-encryption.php';

class GFPaySubs extends GFPaymentAddOn
{

    protected $_min_gravityforms_version = '1.8.20';
    protected $_slug                     = 'gravityformspaysubs';
    protected $_path                     = 'gravityformspaysubs/paysubs.php';
    protected $_full_path                = __FILE__;
    protected $_url                      = 'http://www.gravityforms.com';
    protected $_title                    = 'Gravity Forms PaySubs1 Add-On';
    protected $_short_title              = 'PaySubs';
    protected $_supports_callbacks       = true;
    protected $_capabilities             = array( 'gravityforms_paysubs', 'gravityforms_paysubs_uninstall' );
    // Permissions
    protected $_capabilities_settings_page = 'gravityforms_paysubs';
    protected $_capabilities_form_settings = 'gravityforms_paysubs';
    protected $_capabilities_uninstall     = 'gravityforms_paysubs_uninstall';
    // Automatic upgrade enabled
    protected $_enable_rg_autoupgrade = false;
    protected $_paysubs_url           = 'https://www.vcs.co.za/vvonline/vcspay.aspx';
    // protected $_paysubs_url   = 'https://core3.directpay.online/vcs/pay';
    private static $_instance = null;

    public static function get_instance()
    {
        if ( self::$_instance == null ) {
            self::$_instance = new GFPaySubs();
        }

        return self::$_instance;
    }

    private function __clone()
    {
        /* Do nothing */
    }

    public function init_frontend()
    {
        parent::init_frontend();

        add_filter( 'gform_disable_post_creation', array( $this, 'delay_post' ), 10, 3 );
        add_filter( 'gform_disable_notification', array( $this, 'delay_notification' ), 10, 4 );
    }

    //----- SETTINGS PAGES ----------//
    public function plugin_settings_fields()
    {
        $description = '
            <p style="text-align: left;">' .
        __( 'You will need a PaySubs Terminal Id in order to use the PaySubs Add-On.', 'gravityformspaysubs' ) .
        '</p>
            <ul>
                <li>' . sprintf( __( 'Go to the %sPayGate Website%s in order to register an account.', 'gravityformspaysubs' ), '<a href="https://www.paygate.co.za/" target="_blank">', '</a>' ) . '</li>' .
        '<li>' . __( 'Check \'I understand\' and click on \'Update Settings\' in order to proceed.', 'gravityformspaysubs' ) . '</li>' .
            '</ul>
                <br/>';

        return array(
            array(
                'title'       => '',
                'description' => $description,
                'fields'      => array(
                    array(
                        'name'    => 'gf_paysubs_configured',
                        'label'   => __( 'I understand', 'gravityformspaysubs' ),
                        'type'    => 'checkbox',
                        'choices' => array( array( 'label' => __( '', 'gravityformspaysubs' ), 'name' => 'gf_paysubs_configured' ) ),
                    ),
                    array(
                        'type'     => 'save',
                        'messages' => array(
                            'success' => __( 'Settings have been updated.', 'gravityformspaysubs' ),
                        ),
                    ),
                ),
            ),
        );
    }

    public function feed_list_no_item_message()
    {
        $settings = $this->get_plugin_settings();
        if ( !rgar( $settings, 'gf_paysubs_configured' ) ) {
            return sprintf( __( 'To get started, configure your %sPaySubs Settings%s!', 'gravityformspaysubs' ), '<a href="' . admin_url( 'admin.php?page=gf_settings&subview=' . $this->_slug ) . '">', '</a>' );
        } else {
            return parent::feed_list_no_item_message();
        }
    }

    public function feed_settings_fields()
    {
        $default_settings = parent::feed_settings_fields();

        $fields = array(
            array(
                'name'     => 'PaySubsTerminalID',
                'label'    => __( 'PaySubs1 Terminal ID', 'gravityformspaysubs' ),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => true,
                'tooltip'  => '<h6>' . __( 'PaySubs1 Terminal ID', 'gravityformspaysubs' ) . '</h6>' . __( 'Enter your PaySubs1 Terminal ID.', 'gravityformspaysubs' ),
            ),
            array(
                'name'     => 'PaySubsTitle',
                'label'    => __( 'Title', 'gravityformspaysubs' ),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => true,
                'tooltip'  => '<h6>' . __( 'Title', 'gravityformspaysubs' ) . '</h6>' . __( 'This controls the title which the user sees during checkout.', 'gravityformspaysubs' ),
            ),
            array(
                'name'     => 'PaySubsDescription',
                'label'    => __( 'Description', 'gravityformspaysubs' ),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => true,
                'tooltip'  => '<h6>' . __( 'Description', 'gravityformspaysubs' ) . '</h6>' . __( 'This controls the description which the user sees during checkout.', 'gravityformspaysubs' ),
            ),
            array(
                'name'     => 'PaySubsPersonalAuthenticationMessage',
                'label'    => __( 'Personal Authentication Message', 'gravityformspaysubs' ),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => true,
                'tooltip'  => '<h6>' . __( 'Personal Authentication Message', 'gravityformspaysubs' ) . '</h6>' . __( 'This is the Personal Authentication Message (PAM), added in your PaySubs virtual terminal settings.', 'gravityformspaysubs' ),
            ),
            array(
                'name'          => 'useCustomConfirmationPage',
                'label'         => __( 'Use Custom Confirmation Page', 'gravityformspaysubs' ),
                'type'          => 'radio',
                'choices'       => array(
                    array( 'id' => 'gf_paysubs_thankyou_yes', 'label' => __( 'Yes', 'gravityformspaysubs' ), 'value' => 'yes' ),
                    array( 'id' => 'gf_paysubs_thakyou_no', 'label' => __( 'No', 'gravityformspaysubs' ), 'value' => 'no' ),
                ),
                'horizontal'    => true,
                'default_value' => 'yes',
                'tooltip'       => '<h6>' . __( 'Use Custom Confirmation Page', 'gravityformspaysubs' ) . '</h6>' . __( 'Select Yes to display custom confirmation thank you page to the user.', 'gravityformspaysubs' ),
            ),
            array(
                'name'    => 'successPageUrl',
                'label'   => __( 'Approved Page URL', 'gravityformspaysubs' ),
                'type'    => 'text',
                'class'   => 'medium',
                'tooltip' => '<h6>' . __( 'Successful Page Url', 'gravityformspaysubs' ) . '</h6>' . __( 'Enter a thank you page url when a transaction is successful.', 'gravityformspaysubs' ),
            ),
            array(
                'name'    => 'failedPageUrl',
                'label'   => __( 'Declined Page Url', 'gravityformspaysubs' ),
                'type'    => 'text',
                'class'   => 'medium',
                'tooltip' => '<h6>' . __( 'Failed Page Url', 'gravityformspaysubs' ) . '</h6>' . __( 'Enter a thank you page url when a transaction is failed.', 'gravityformspaysubs' ),
            ),
            array(
                'name'    => 'customField1',
                'label'   => __( 'Custom Field 1', 'gravityformspaysubs' ),
                'type'    => 'text',
                'class'   => 'medium',
                'tooltip' => '<h6>' . __( 'Custom Field 1', 'gravityformspaysubs' ) . '</h6>' . __( 'Custom information to be passed to PaySubs', 'gravityformspaysubs' ),
            ),
            array(
                'name'    => 'customField2',
                'label'   => __( 'Custom Field 2', 'gravityformspaysubs' ),
                'type'    => 'text',
                'class'   => 'medium',
                'tooltip' => '<h6>' . __( 'Custom Field 2', 'gravityformspaysubs' ) . '</h6>' . __( 'Custom information to be passed to PaySubs', 'gravityformspaysubs' ),
            ),
            array(
                'name'    => 'customField3',
                'label'   => __( 'Custom Field 3', 'gravityformspaysubs' ),
                'type'    => 'text',
                'class'   => 'medium',
                'tooltip' => '<h6>' . __( 'Custom Field 3', 'gravityformspaysubs' ) . '</h6>' . __( 'Custom information to be passed to PaySubs', 'gravityformspaysubs' ),
            ),
        );

        $default_settings = parent::add_field_after( 'feedName', $fields, $default_settings );
        //--------------------------------------------------------------------------------------

        $message = array(
            'name'  => 'message',
            'label' => __( 'PaySubs1 does not currently support subscription billing', 'gravityformsstripe' ),
            'style' => 'width:40px;text-align:center;',
            'type'  => 'checkbox',
        );
        $default_settings = $this->add_field_after( 'trial', $message, $default_settings );

        $default_settings = $this->remove_field( 'recurringTimes', $default_settings );
        $default_settings = $this->remove_field( 'billingCycle', $default_settings );
        $default_settings = $this->remove_field( 'recurringAmount', $default_settings );
        $default_settings = $this->remove_field( 'setupFee', $default_settings );
        $default_settings = $this->remove_field( 'trial', $default_settings );

        // Add donation to transaction type drop down
        $transaction_type = parent::get_field( 'transactionType', $default_settings );
        $choices          = $transaction_type['choices'];
        $add_donation     = false;
        foreach ( $choices as $choice ) {
            // Add donation option if it does not already exist
            if ( $choice['value'] == 'donation' ) {
                $add_donation = false;
            }
        }
        if ( $add_donation ) {
            // Add donation transaction type
            $choices[] = array( 'label' => __( 'Donations', 'gravityformspaysubs' ), 'value' => 'donation' );
        }
        $transaction_type['choices'] = $choices;
        $default_settings            = $this->replace_field( 'transactionType', $transaction_type, $default_settings );
        //-------------------------------------------------------------------------------------------------
        // Add Page Style, Continue Button Label, Cancel URL
        $fields = array(
            array(
                'name'     => 'continueText',
                'label'    => __( 'Continue Button Label', 'gravityformspaysubs' ),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => false,
                'tooltip'  => '<h6>' . __( 'Continue Button Label', 'gravityformspaysubs' ) . '</h6>' . __( 'Enter the text that should appear on the continue button once payment has been completed via PaySubs.', 'gravityformspaysubs' ),
            ),
            array(
                'name'     => 'cancelUrl',
                'label'    => __( 'Cancel URL', 'gravityformspaysubs' ),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => false,
                'tooltip'  => '<h6>' . __( 'Cancel URL', 'gravityformspaysubs' ) . '</h6>' . __( 'Enter the URL the user should be sent to should they cancel before completing their payment. It currently defaults to the PaySubs website.', 'gravityformspaysubs' ),
            ),
        );

        // Add post fields if form has a post
        $form = $this->get_current_form();
        if ( GFCommon::has_post_field( $form['fields'] ) ) {
            $post_settings = array(
                'name'    => 'post_checkboxes',
                'label'   => __( 'Posts', 'gravityformspaysubs' ),
                'type'    => 'checkbox',
                'tooltip' => '<h6>' . __( 'Posts', 'gravityformspaysubs' ) . '</h6>' . __( 'Enable this option if you would like to only create the post after payment has been received.', 'gravityformspaysubs' ),
                'choices' => array(
                    array( 'label' => __( 'Create post only when payment is received.', 'gravityformspaysubs' ), 'name' => 'delayPost' ),
                ),
            );

            if ( $this->get_setting( 'transactionType' ) == 'subscription' ) {
                $post_settings['choices'][] = array(
                    'label'    => __( 'Change post status when subscription is canceled.', 'gravityformspaysubs' ),
                    'name'     => 'change_post_status',
                    'onChange' => 'var action = this.checked ? "draft" : ""; jQuery("#update_post_action").val(action);',
                );
            }

            $fields[] = $post_settings;
        }

        // Adding custom settings for backwards compatibility with hook 'gform_paysubs_add_option_group'
        $fields[] = array(
            'name'  => 'custom_options',
            'label' => '',
            'type'  => 'custom',
        );

        //-----------------------------------------------------------------------------------------
        // Get billing info section and add customer first/last name
        $billing_info   = parent::get_field( 'billingInformation', $default_settings );
        $billing_fields = $billing_info['field_map'];
        $add_first_name = true;
        $add_last_name  = true;
        foreach ( $billing_fields as $mapping ) {
            // Add first/last name if it does not already exist in billing fields
            if ( $mapping['name'] == 'firstName' ) {
                $add_first_name = false;
            } elseif ( $mapping['name'] == 'lastName' ) {
                $add_last_name = false;
            }
        }

        if ( $add_last_name ) {
            // Add last name
            array_unshift( $billing_info['field_map'], array( 'name' => 'lastName', 'label' => __( 'Last Name', 'gravityformspaysubs' ), 'required' => false ) );
        }
        if ( $add_first_name ) {
            array_unshift( $billing_info['field_map'], array( 'name' => 'firstName', 'label' => __( 'First Name', 'gravityformspaysubs' ), 'required' => false ) );
        }
        $default_settings = parent::replace_field( 'billingInformation', $billing_info, $default_settings );

        return apply_filters( 'gform_paysubs_feed_settings_fields', $default_settings, $form );
    }

    public function field_map_title()
    {
        return __( 'PaySubs Field', 'gravityformspaysubs' );
    }

    public function settings_trial_period( $field, $echo = true )
    {
        // Use the parent billing cycle function to make the drop down for the number and type
        $html = parent::settings_billing_cycle( $field );

        return $html;
    }

    public function set_trial_onchange( $field )
    {
        // Return the javascript for the onchange event
        return "
        if(jQuery(this).prop('checked')){
            jQuery('#{$field['name']}_product').show('slow');
            jQuery('#gaddon-setting-row-trialPeriod').show('slow');
            if (jQuery('#{$field['name']}_product').val() == 'enter_amount'){
                jQuery('#{$field['name']}_amount').show('slow');
            }
            else{
                jQuery('#{$field['name']}_amount').hide();
            }
        }
        else {
            jQuery('#{$field['name']}_product').hide('slow');
            jQuery('#{$field['name']}_amount').hide();
            jQuery('#gaddon-setting-row-trialPeriod').hide('slow');
        }";
    }

    public function settings_options( $field, $echo = true )
    {
        $checkboxes = array(
            'name'    => 'options_checkboxes',
            'type'    => 'checkboxes',
            'choices' => array(
                array( 'label' => __( 'Do not prompt buyer to include a shipping address.', 'gravityformspaysubs' ), 'name' => 'disableShipping' ),
                array( 'label' => __( 'Do not prompt buyer to include a note with payment.', 'gravityformspaysubs' ), 'name' => 'disableNote' ),
            ),
        );

        $html = $this->settings_checkbox( $checkboxes, false );

        //--------------------------------------------------------
        // For backwards compatibility.
        ob_start();
        do_action( 'gform_paysubs_action_fields', $this->get_current_feed(), $this->get_current_form() );
        $html .= ob_get_clean();
        //--------------------------------------------------------

        if ( $echo ) {
            echo $html;
        }

        return $html;
    }

    public function settings_custom( $field, $echo = true )
    {
        ob_start();
        ?>
        <div id='gf_paysubs_custom_settings'>
        <?php
do_action( 'gform_paysubs_add_option_group', $this->get_current_feed(), $this->get_current_form() );
        ?>
        </div>

        <script type='text/javascript'>
            jQuery(document).ready(function () {
                jQuery('#gf_paysubs_custom_settings label.left_header').css('margin-left', '-200px');
            });
        </script>

            <?php
$html = ob_get_clean();

        if ( $echo ) {
            echo $html;
        }

        return $html;
    }

    public function checkbox_input_change_post_status( $choice, $attributes, $value, $tooltip )
    {
        $markup = $this->checkbox_input( $choice, $attributes, $value, $tooltip );

        $dropdown_field = array(
            'name'     => 'update_post_action',
            'choices'  => array(
                array( 'label' => '' ),
                array( 'label' => __( 'Mark Post as Draft', 'gravityformspaysubs' ), 'value' => 'draft' ),
                array( 'label' => __( 'Delete Post', 'gravityformspaysubs' ), 'value' => 'delete' ),
            ),
            'onChange' => "var checked = jQuery(this).val() ? 'checked' : false; jQuery('#change_post_status').attr('checked', checked);",
        );
        $markup .= '&nbsp;&nbsp;' . $this->settings_select( $dropdown_field, false );

        return $markup;
    }

    public function option_choices()
    {
        return false;
    }

    public function save_feed_settings( $feed_id, $form_id, $settings )
    {
        //--------------------------------------------------------
        // For backwards compatibility
        $feed = $this->get_feed( $feed_id );

        // Saving new fields into old field names to maintain backwards compatibility for delayed payments
        $settings['type'] = $settings['transactionType'];

        if ( isset( $settings['recurringAmount'] ) ) {
            $settings['recurring_amount_field'] = $settings['recurringAmount'];
        }

        $feed['meta'] = $settings;
        $feed         = apply_filters( 'gform_paysubs_save_config', $feed );

        // Call hook to validate custom settings/meta added using gform_paysubs_action_fields or gform_paysubs_add_option_group action hooks
        $is_validation_error = apply_filters( 'gform_paysubs_config_validation', false, $feed );
        if ( $is_validation_error ) {
            // Fail save
            return false;
        }

        $settings = $feed['meta'];

        //--------------------------------------------------------

        return parent::save_feed_settings( $feed_id, $form_id, $settings );
    }

    //------ SENDING TO PaySubs -----------//

    public function redirect_url( $feed, $submission_data, $form, $entry )
    {

        // Don't process redirect url if request is a PaySubs return
        if ( !rgempty( 'gf_paysubs_return', $_GET ) && !isset( $_GET['retry'] ) && !$_GET['retry'] ) {
            return false;
        }
        if ( !rgempty( $feed['meta']['PaySubsTerminalID'] ) ) {
            return false;
        }

        // Updating lead's payment_status to Processing
        GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Pending' );

        $return_url = $this->return_url( $form['id'], $entry['id'], $entry['created_by'], $feed['id'] );
        $eid        = $entry['id'];
        $return_url = add_query_arg( array( 'eid' => $eid ), $return_url );

        $params = array(
            'p1'  => $feed['meta']['PaySubsTerminalID'],
            'p2'  => $entry['id'],
            'p3'  => sprintf( __( '%s purchase, Order # %d', 'wc_paysubs' ), get_bloginfo( 'name' ), $entry['id'] ),
            'p4'  => number_format( GFCommon::get_order_total( $form, $entry ), 2, '.', ',' ),
            'p5'  => GFCommon::get_currency(),
            'p10' => add_query_arg( array( 'eid' => $eid, 'retry' => true ), $return_url ), // The URL to direct to when the customer clicks "Cancel" on PaySubs.
        );

        //Add in the custom fields if they are not empty
        if ( $feed['meta']['customField1'] != '' ) {
            $params['m_1'] = $feed['meta']['customField1'];
        }
        if ( $feed['meta']['customField2'] != '' ) {
            $params['m_2'] = $feed['meta']['customField2'];
        }
        if ( $feed['meta']['customField3'] != '' ) {
            $params['m_3'] = $feed['meta']['customField3'];
        }

        $params['m_4'] = $form['id']; // This is returned to us when returning from PaySubs.

        if ( $feed['meta']['PaySubsPersonalAuthenticationMessage'] != '' ) {
            $params['m_5'] = md5( $feed['meta']['PaySubsPersonalAuthenticationMessage'] . '::' . $params['p2'] );
        }

        $md5_hash_str = implode( '', $params ) . 'secret';
        $md5_hash     = md5( $md5_hash_str );

        $params['UrlsProvided'] = 'Y';
        $params['ApprovedUrl']  = $return_url;
        $params['DeclinedUrl']  = $return_url;

        $paysubs_args_array = array();
        foreach ( $params as $key => $value ) {
            $paysubs_args_array[] = '<input type="hidden" name="' . $key . '" value="' . $value . '" />';
        }
        print '<form action="' . $this->_paysubs_url . '" method="post" id="paysubs_payment_form" name="paysubs_payment_form">
                    ' . implode( '', $paysubs_args_array ) . '
                    <input type="hidden" name="Hash" value="' . $md5_hash . '" />
                    <script type="text/javascript">
                        document.forms["paysubs_payment_form"].submit();
                    </script>
                </form>';
    }

    public function customer_query_string( $feed, $lead )
    {
        $fields = '';
        foreach ( $this->get_customer_fields() as $field ) {
            $field_id = $feed['meta'][$field['meta_name']];
            $value    = rgar( $lead, $field_id );

            if ( $field['name'] == 'country' ) {
                $value = class_exists( 'GF_Field_Address' ) ? GF_Fields::get( 'address' )->get_country_code( $value ) : GFCommon::get_country_code( $value );
            } elseif ( $field['name'] == 'state' ) {
                $value = class_exists( 'GF_Field_Address' ) ? GF_Fields::get( 'address' )->get_us_state_code( $value ) : GFCommon::get_us_state_code( $value );
            }

            if ( !empty( $value ) ) {
                $fields .= "&{$field['name']}=" . urlencode( $value );
            }
        }

        return $fields;
    }

    public function return_url( $form_id, $lead_id, $user_id, $feed_id )
    {
        $pageURL = GFCommon::is_ssl() ? 'https://' : 'http://';

        $server_port = apply_filters( 'gform_paysubs_return_url_port', $_SERVER['SERVER_PORT'] );

        if ( $server_port != '80' ) {
            $pageURL .= $_SERVER['SERVER_NAME'] . ':' . $server_port . $_SERVER['REQUEST_URI'];
        } else {
            $pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
        }

        $ids_query = "ids={$form_id}|{$lead_id}|{$user_id}|{$feed_id}";
        $ids_query .= '&hash=' . wp_hash( $ids_query );
        $encrpyt_ids_query = PaySubs_encryption( $ids_query, 'e' );
        return add_query_arg( 'gf_paysubs_return', $encrpyt_ids_query, $pageURL );
    }

    public static function paysubs_thankyou_page()
    {

        $instance = self::get_instance();

        if ( !$instance->is_gravityforms_supported() ) {
            return;
        }

        if ( !empty( $_POST ) && isset( $_POST['p2'] ) && is_numeric( $_POST['p2'] ) ) {
            $data = stripslashes_deep( $_POST );

            $has_error     = false;
            $error_message = '';
            $is_done       = false;
            $status_desc   = 'failed';

            $lead_id = (int) $data['p2'];
            $form_id = (int) $data['m_4'];
            $feed_id = $instance->get_paysubs_feed_by_entry( $lead_id );
            $form    = GFAPI::get_form( $form_id );
            $lead    = GFAPI::get_entry( $lead_id );
            $feed    = GFAPI::get_feeds( $feed_id, $form_id, null, true );

            $instance->log_debug( "\n" . '----------' . "\n" . 'PaySubs response received' );

            GFAPI::update_entry_property( $lead_id, 'transaction_id', $data['Uti'] );
            GFAPI::update_entry_property( $lead_id, 'payment_date', gmdate( 'y-m-d H:i:s' ) );
            GFAPI::update_entry_property( $lead_id, 'payment_amount', $data['p6'] );

            // check transaction password
            $pam = $feed[0]['meta']['PaySubsPersonalAuthenticationMessage'];
            if ( $pam != '' && $data['pam'] != '' && !$has_error && !$is_done ) {
                if ( $pam != $data['pam'] ) {
                    $has_error = true;

                    $error_message = 'Transaction password incorrect.';
                    $instance->log_debug( $error_message );
                }
                if ( $data['m_5'] != md5( $data['pam'] . '::' . $data['p2'] ) && !$has_error && !$is_done ) {
                    $has_error = true;

                    $error_message = 'Checksum mismatch.';
                    $instance->log_debug( $error_message );
                }
                GFAPI::update_entry_property( $lead_id, 'payment_status', 'Failed' );
                GFFormsModel::add_note( $lead_id, '', 'PaySubs Web Response', $error_message );

                $cancelURL   = ( !empty( $feed['0']['meta']['failedPageUrl'] ) ) ? esc_url( $feed['0']['meta']['failedPageUrl'] ) : home_url();
                $eid         = PaySubs_encryption( $lead_id, 'e' );
                $redirectURL = add_query_arg( array( 'eid' => $eid, 'retry' => true ), $cancelURL );
            }

            // check transaction status
            if ( !empty( $data['p3'] ) && substr( $data['p3'], 6, 8 ) != 'APPROVED' && !$has_error && !$is_done ) {
                $has_error = true;

                $error_message = 'Transaction was not successful.';
                $instance->log_debug( $error_message );

                GFAPI::update_entry_property( $lead_id, 'payment_status', 'Failed' );
                GFFormsModel::add_note( $lead_id, '', 'PaySubs Web Response', $error_message );

                $cancelURL   = ( !empty( $feed['0']['meta']['failedPageUrl'] ) ) ? esc_url( $feed['0']['meta']['failedPageUrl'] ) : home_url();
                $eid         = PaySubs_encryption( $lead_id, 'e' );
                $redirectURL = add_query_arg( array( 'eid' => $eid, 'retry' => true ), $cancelURL );
            }

            // Get data sent by the gateway
            if ( !$has_error && !$is_done ) {
                $instance->log_debug( 'Get posted data' );

                $instance->log_debug( 'PaySubs Data: ' . print_r( $data, true ) );

                if ( $data === false ) {
                    $has_error     = true;
                    $error_message = 'Bad access on page.';
                }
            }

            // If an error occurred
            if ( $has_error ) {
                $instance->log_debug( 'Error occurred: ' . $error_message );
                $is_done = false;
            } else {
                $status_desc = 'approved';
                $instance->log_debug( 'Transaction completed.' );
                $is_done = true;

                // Payment completed
                GFAPI::update_entry_property( $lead_id, 'payment_status', 'Approved' );
                GFFormsModel::add_note( $lead_id, '', 'PaySubs Redirect Response', 'Payment via PaySubs completed. Response: ' . $data['p3'] );

                $successURL  = ( !empty( $feed['0']['meta']['successPageUrl'] ) ) ? esc_url( $feed['0']['meta']['successPageUrl'] ) : home_url();
                $eid         = PaySubs_encryption( $lead_id, 'e' );
                $redirectURL = add_query_arg( array( 'eid' => $eid ), $successURL );
            }
            if ( $feed['0']['meta']['useCustomConfirmationPage'] == 'yes' ) {
                wp_redirect( $redirectURL );
                die;
            } else {
                if ( !class_exists( 'GFFormDisplay' ) ) {
                    require_once GFCommon::get_base_path() . '/form_display.php';
                }

                $confirmation_msg = 'Thanks for contacting us! We will get in touch with you shortly.';
                // Display the correct message depending on transaction status
                foreach ( $form['confirmations'] as $row ) {
                    foreach ( $row as $key => $val ) {
                        if ( is_array( $val ) || empty( $val ) ) {
                            continue;
                        }
                        // This condition does NOT working when using the Custom Confirmation Page setting
                        if ( $status_desc == strtolower( str_replace( ' ', '', $val ) ) ) {
                            $confirmation_msg = $row['message'];
                            $confirmation_msg = apply_filters( 'the_content', $confirmation_msg );
                            $confirmation_msg = str_replace( ']]>', ']]&gt;', $confirmation_msg );
                        }
                    }
                }
                $confirmation_msg                    = apply_filters( 'the_content', $confirmation_msg );
                GFFormDisplay::$submission[$form_id] = array( 'is_confirmation' => true, 'confirmation_message' => $confirmation_msg, 'form' => $form, 'lead' => $lead );
            }
        }
    }

    public function get_customer_fields()
    {
        return array(
            array( 'name' => 'first_name', 'label' => 'First Name', 'meta_name' => 'billingInformation_firstName' ),
            array( 'name' => 'last_name', 'label' => 'Last Name', 'meta_name' => 'billingInformation_lastName' ),
            array( 'name' => 'email', 'label' => 'Email', 'meta_name' => 'billingInformation_email' ),
            array( 'name' => 'address1', 'label' => 'Address', 'meta_name' => 'billingInformation_address' ),
            array( 'name' => 'address2', 'label' => 'Address 2', 'meta_name' => 'billingInformation_address2' ),
            array( 'name' => 'city', 'label' => 'City', 'meta_name' => 'billingInformation_city' ),
            array( 'name' => 'state', 'label' => 'State', 'meta_name' => 'billingInformation_state' ),
            array( 'name' => 'zip', 'label' => 'Zip', 'meta_name' => 'billingInformation_zip' ),
            array( 'name' => 'country', 'label' => 'Country', 'meta_name' => 'billingInformation_country' ),
        );
    }

    public function convert_interval( $interval, $to_type )
    {
        // Convert single character into long text for new feed settings or convert long text into single character for sending to PaySubs
        // $to_type: text (change character to long text), OR char (change long text to character)
        if ( empty( $interval ) ) {
            return '';
        }

        if ( $to_type == 'text' ) {
            // Convert single char to text
            switch ( strtoupper( $interval ) ) {
                case 'D':
                    $new_interval = 'day';
                    break;
                case 'W':
                    $new_interval = 'week';
                    break;
                case 'M':
                    $new_interval = 'month';
                    break;
                case 'Y':
                    $new_interval = 'year';
                    break;
                default:
                    $new_interval = $interval;
                    break;
            }
        } else {
            // Convert text to single char
            switch ( strtolower( $interval ) ) {
                case 'day':
                    $new_interval = 'D';
                    break;
                case 'week':
                    $new_interval = 'W';
                    break;
                case 'month':
                    $new_interval = 'M';
                    break;
                case 'year':
                    $new_interval = 'Y';
                    break;
                default:
                    $new_interval = $interval;
                    break;
            }
        }

        return $new_interval;
    }

    public function delay_post( $is_disabled, $form, $entry )
    {
        $feed            = $this->get_payment_feed( $entry );
        $submission_data = $this->get_submission_data( $feed, $form, $entry );

        if ( !$feed || empty( $submission_data['payment_amount'] ) ) {
            return $is_disabled;
        }

        return !rgempty( 'delayPost', $feed['meta'] );
    }

    public function delay_notification( $is_disabled, $notification, $form, $entry )
    {
        $this->log_debug( 'Delay notification ' . $notification . ' for ' . $entry['id'] . '.' );
        $feed            = $this->get_payment_feed( $entry );
        $submission_data = $this->get_submission_data( $feed, $form, $entry );

        if ( !$feed || empty( $submission_data['payment_amount'] ) ) {
            return $is_disabled;
        }

        $selected_notifications = is_array( rgar( $feed['meta'], 'selectedNotifications' ) ) ? rgar( $feed['meta'], 'selectedNotifications' ) : array();

        return isset( $feed['meta']['delayNotification'] ) && in_array( $notification['id'], $selected_notifications ) ? true : $is_disabled;
    }

    //------- PROCESSING PaySubs (Callback) -----------//

    public function get_payment_feed( $entry, $form = false )
    {
        $feed = parent::get_payment_feed( $entry, $form );

        if ( empty( $feed ) && !empty( $entry['id'] ) ) {
            // Looking for feed created by legacy versions
            $feed = $this->get_paysubs_feed_by_entry( $entry['id'] );
        }

        $feed = apply_filters( 'gform_paysubs_get_payment_feed', $feed, $entry, $form );

        return $feed;
    }

    private function get_paysubs_feed_by_entry( $entry_id )
    {
        $feed_id = gform_get_meta( $entry_id, 'paysubs_feed_id' );
        $feed    = $this->get_feed( $feed_id );

        return !empty( $feed ) ? $feed : false;
    }

    // Notification
    public static function paysubs_notify_handler()
    {

        if ( isset( $_GET["page"] ) ) {
            // Notify PaySubs that the request was successful
            echo "OK";

            $instance = self::get_instance();

            if ( !$instance->is_gravityforms_supported() ) {
                return;
            }

            if ( !empty( $_POST ) && isset( $_POST['p2'] ) && is_numeric( $_POST['p2'] ) ) {
                $data = stripslashes_deep( $_POST );

                $has_error                  = false;
                $payment_notification_event = '';

                $lead_id = (int) $data['p2'];
                $form_id = (int) $data['m_2'];
                $feed_id = $instance->get_paysubs_feed_by_entry( $lead_id );
                $form    = GFAPI::get_form( $form_id );
                $feed    = GFAPI::get_feeds( $feed_id, $form_id, null, true );

                // get entry
                $entry = GFAPI::get_entry( $lead_id );
                if ( !$entry ) {
                    $instance->log_error( "Entry could not be found. Entry ID: {$lead_id}. Aborting." );
                    return;
                }
                $instance->log_debug( "Entry has been found." . print_r( $entry, true ) );

                // check transaction password
                $pam = $feed[0]['meta']['PaySubsPersonalAuthenticationMessage'];
                if ( $pam != '' && $data['pam'] != '' && !$has_error && !$is_done ) {
                    if ( $pam != $data['pam'] ) {
                        $has_error = true;
                    }
                    if ( $data['m_1'] != md5( $data['pam'] . '::' . $data['p2'] ) && !$has_error && !$is_done ) {
                        $has_error = true;
                    }
                }

                // check transaction status
                if ( !empty( $data['p3'] ) && substr( $data['p3'], 6, 8 ) != 'APPROVED' && !$has_error && !$is_done ) {
                    $has_error = true;
                }

                // Get data sent by the gateway
                if ( !$has_error && !$is_done ) {
                    if ( $data === false ) {
                        $has_error = true;
                    }
                }

                // If an error occurred
                if ( $has_error ) {
                    $instance->log_debug( 'Error occurred: ' . $error_message );
                    $is_done = false;
                } else {
                    $instance->log_debug( 'Send notifications.' );
                    $instance->log_debug( $entry );
                    $payment_notification_event = 'approved_payment';

                    // Send payment event specific comms that tap into GravityForms Payment events
                    // https://www.gravityhelp.com/documentation/article/send-notifications-on-payment-events/
                    $instance->log_debug( 'Payment notification event: ' . $payment_notification_event );
                    GFAPI::send_notifications( $form, $entry, $payment_notification_event );
                }
            }
        }
    }

    public function get_entry( $custom_field )
    {
        if ( empty( $custom_field ) ) {
            $this->log_error( __METHOD__ . '(): ITN request does not have a custom field, so it was not created by Gravity Forms. Aborting.' );

            return false;
        }

        // Getting entry associated with this ITN message (entry id is sent in the 'custom' field)
        list( $entry_id, $hash ) = explode( '|', $custom_field );
        $hash_matches          = wp_hash( $entry_id ) == $hash;

        // Allow the user to do some other kind of validation of the hash
        $hash_matches = apply_filters( 'gform_paysubs_hash_matches', $hash_matches, $entry_id, $hash, $custom_field );

        // Validates that Entry Id wasn't tampered with
        if ( !rgpost( 'test_itn' ) && !$hash_matches ) {
            $this->log_error( __METHOD__ . "(): Entry Id verification failed. Hash does not match. Custom field: {$custom_field}. Aborting." );

            return false;
        }

        $this->log_debug( __METHOD__ . "(): ITN message has a valid custom field: {$custom_field}" );

        $entry = GFAPI::get_entry( $entry_id );

        if ( is_wp_error( $entry ) ) {
            $this->log_error( __METHOD__ . '(): ' . $entry->get_error_message() );

            return false;
        }

        return $entry;
    }

    public function modify_post( $post_id, $action )
    {

        $result = false;

        if ( !$post_id ) {
            return $result;
        }

        switch ( $action ) {
            case 'draft':
                $post              = get_post( $post_id );
                $post->post_status = 'draft';
                $result            = wp_update_post( $post );
                $this->log_debug( __METHOD__ . "(): Set post (#{$post_id}) status to \"draft\"." );
                break;
            case 'delete':
                $result = wp_delete_post( $post_id );
                $this->log_debug( __METHOD__ . "(): Deleted post (#{$post_id})." );
                break;
        }

        return $result;
    }

    public function is_callback_valid()
    {
        if ( rgget( 'page' ) != 'gf_paysubs' ) {
            return false;
        }

        return true;
    }

    //------- AJAX FUNCTIONS ------------------//

    public function init_ajax()
    {
        parent::init_ajax();

        add_action( 'wp_ajax_gf_dismiss_paysubs_menu', array( $this, 'ajax_dismiss_menu' ) );
    }

    //------- ADMIN FUNCTIONS/HOOKS -----------//

    public function init_admin()
    {

        parent::init_admin();

        // Add actions to allow the payment status to be modified
        add_action( 'gform_payment_status', array( $this, 'admin_edit_payment_status' ), 3, 3 );

        if ( version_compare( GFCommon::$version, '1.8.17.4', '<' ) ) {
            // Using legacy hook
            add_action( 'gform_entry_info', array( $this, 'admin_edit_payment_status_details' ), 4, 2 );
        } else {
            add_action( 'gform_payment_date', array( $this, 'admin_edit_payment_date' ), 3, 3 );
            add_action( 'gform_payment_transaction_id', array( $this, 'admin_edit_payment_transaction_id' ), 3, 3 );
            add_action( 'gform_payment_amount', array( $this, 'admin_edit_payment_amount' ), 3, 3 );
        }

        add_action( 'gform_after_update_entry', array( $this, 'admin_update_payment' ), 4, 2 );

        add_filter( 'gform_addon_navigation', array( $this, 'maybe_create_menu' ) );

        add_filter( 'gform_notification_events', array( $this, 'notification_events_dropdown' ) );
    }

    public function notification_events_dropdown( $notification_events )
    {
        $payment_events = array(
            'approved_payment' => __( 'Payment Approved', 'gravityforms' ),
            // 'declined_payment'       => __( 'Payment Declined', 'gravityforms' ),
        );

        return array_merge( $notification_events, $payment_events );
    }

    public function maybe_create_menu( $menus )
    {
        $current_user         = wp_get_current_user();
        $dismiss_paysubs_menu = get_metadata( 'user', $current_user->ID, 'dismiss_paysubs_menu', true );
        if ( $dismiss_paysubs_menu != '1' ) {
            $menus[] = array( 'name' => $this->_slug, 'label' => $this->get_short_title(), 'callback' => array( $this, 'temporary_plugin_page' ), 'permission' => $this->_capabilities_form_settings );
        }

        return $menus;
    }

    public function ajax_dismiss_menu()
    {
        $current_user = wp_get_current_user();
        update_metadata( 'user', $current_user->ID, 'dismiss_paysubs_menu', '1' );
    }

    public function temporary_plugin_page()
    {
        ?>
        <script type="text/javascript">
            function dismissMenu() {
                jQuery('#gf_spinner').show();
                jQuery.post(ajaxurl, {
                    action: "gf_dismiss_paysubs_menu"
                },
                          function (response) {
                              document.location.href = '?page=gf_edit_forms';
                              jQuery('#gf_spinner').hide();
                          }
                );

            }
        </script>

        <div class="wrap about-wrap">
            <h1><?php _e( 'PaySubs Web Add-On', 'gravityformspaysubs' )?></h1>
            <div class="about-text"><?php _e( 'Thank you for updating! The new version of the Gravity Forms PaySubs Add-On makes changes to how you manage your PaySubs integration.', 'gravityformspaysubs' )?></div>
            <div class="changelog">
                <hr/>
                <div class="feature-section col two-col">
                    <div class="col-1">
                        <h3><?php _e( 'Manage PaySubs Contextually', 'gravityformspaysubs' )?></h3>
                        <p><?php _e( 'PaySubs Feeds are now accessed via the PaySubs sub-menu within the Form Settings for the Form you would like to integrate PaySubs with.', 'gravityformspaysubs' )?></p>
                    </div>
                </div>

                <hr/>

                <form method="post" id="dismiss_menu_form" style="margin-top: 20px;">
                    <input type="checkbox" name="dismiss_paysubs_menu" value="1" onclick="dismissMenu();"> <label><?php _e( 'I understand, dismiss this message!', 'gravityformspaysubs' )?></label>
                    <img id="gf_spinner" src="<?php echo GFCommon::get_base_url() . '/images/spinner.gif' ?>" alt="<?php _e( 'Please wait...', 'gravityformspaysubs' )?>" style="display:none;"/>
                </form>

            </div>
        </div>
        <?php
}

    public function admin_edit_payment_status( $payment_status, $form, $lead )
    {
        // Allow the payment status to be edited when for paysubs, not set to Approved/Paid, and not a subscription
        if ( !$this->is_payment_gateway( $lead['id'] ) || strtolower( rgpost( 'save' ) ) != 'edit' || $payment_status == 'Approved' || $payment_status == 'Paid' || rgar( $lead, 'transaction_type' ) == 2 ) {
            return $payment_status;
        }

        // Create drop down for payment status
        $payment_string = gform_tooltip( 'paysubs_edit_payment_status', '', true );
        $payment_string .= '<select id="payment_status" name="payment_status">';
        $payment_string .= '<option value="' . $payment_status . '" selected>' . $payment_status . '</option>';
        $payment_string .= '<option value="Paid">Paid</option>';
        $payment_string .= '</select>';

        return $payment_string;
    }

    public function admin_edit_payment_date( $payment_date, $form, $lead )
    {
        // Allow the payment date to be edited
        if ( !$this->is_payment_gateway( $lead['id'] ) || strtolower( rgpost( 'save' ) ) != 'edit' ) {
            return $payment_date;
        }

        $payment_date = $lead['payment_date'];
        if ( empty( $payment_date ) ) {
            $payment_date = gmdate( 'y-m-d H:i:s' );
        }

        $input = '<input type="text" id="payment_date" name="payment_date" value="' . $payment_date . '">';

        return $input;
    }

    public function admin_edit_payment_transaction_id( $transaction_id, $form, $lead )
    {
        // Allow the transaction ID to be edited
        if ( !$this->is_payment_gateway( $lead['id'] ) || strtolower( rgpost( 'save' ) ) != 'edit' ) {
            return $transaction_id;
        }

        $input = '<input type="text" id="paysubs_transaction_id" name="paysubs_transaction_id" value="' . $transaction_id . '">';

        return $input;
    }

    public function admin_edit_payment_amount( $payment_amount, $form, $lead )
    {
        // Allow the payment amount to be edited
        if ( !$this->is_payment_gateway( $lead['id'] ) || strtolower( rgpost( 'save' ) ) != 'edit' ) {
            return $payment_amount;
        }

        if ( empty( $payment_amount ) ) {
            $payment_amount = GFCommon::get_order_total( $form, $lead );
        }

        $input = '<input type="text" id="payment_amount" name="payment_amount" class="gform_currency" value="' . $payment_amount . '">';

        return $input;
    }

    public function admin_edit_payment_status_details( $form_id, $lead )
    {
        $form_action = strtolower( rgpost( 'save' ) );
        if ( !$this->is_payment_gateway( $lead['id'] ) || $form_action != 'edit' ) {
            return;
        }

        // Get data from entry to pre-populate fields
        $payment_amount = rgar( $lead, 'payment_amount' );
        if ( empty( $payment_amount ) ) {
            $form           = GFFormsModel::get_form_meta( $form_id );
            $payment_amount = GFCommon::get_order_total( $form, $lead );
        }
        $transaction_id = rgar( $lead, 'transaction_id' );
        $payment_date   = rgar( $lead, 'payment_date' );
        if ( empty( $payment_date ) ) {
            $payment_date = gmdate( 'y-m-d H:i:s' );
        }

        // Display edit fields
        ?>
        <div id="edit_payment_status_details" style="display:block">
            <table>
                <tr>
                    <td colspan="2"><strong>Payment Information</strong></td>
                </tr>

                <tr>
                    <td>Date:<?php gform_tooltip( 'paysubs_edit_payment_date' )?></td>
                    <td>
                        <input type="text" id="payment_date" name="payment_date" value="<?php echo $payment_date ?>">
                    </td>
                </tr>
                <tr>
                    <td>Amount:<?php gform_tooltip( 'paysubs_edit_payment_amount' )?></td>
                    <td>
                        <input type="text" id="payment_amount" name="payment_amount" class="gform_currency" value="<?php echo $payment_amount ?>">
                    </td>
                </tr>
                <tr>
                    <td nowrap>Transaction ID:<?php gform_tooltip( 'paysubs_edit_payment_transaction_id' )?></td>
                    <td>
                        <input type="text" id="paysubs_transaction_id" name="paysubs_transaction_id" value="<?php echo $transaction_id ?>">
                    </td>
                </tr>
            </table>
        </div>
        <?php
}

    public function admin_update_payment( $form, $lead_id )
    {
        check_admin_referer( 'gforms_save_entry', 'gforms_save_entry' );

        // Update payment information in admin, need to use this function so the lead data is updated before displayed in the sidebar info section
        $form_action = strtolower( rgpost( 'save' ) );
        if ( !$this->is_payment_gateway( $lead_id ) || $form_action != 'update' ) {
            return;
        }
        // Get lead
        $lead = GFFormsModel::get_lead( $lead_id );

        // Check if current payment status is processing
        if ( $lead['payment_status'] != 'Processing' ) {
            return;
        }

        // Get payment fields to update
        $payment_status = $_POST['payment_status'];
        // When updating, payment status may not be editable, if no value in post, set to lead payment status
        if ( empty( $payment_status ) ) {
            $payment_status = $lead['payment_status'];
        }

        $payment_amount      = GFCommon::to_number( rgpost( 'payment_amount' ) );
        $payment_transaction = rgpost( 'paysubs_transaction_id' );
        $payment_date        = rgpost( 'payment_date' );
        if ( empty( $payment_date ) ) {
            $payment_date = gmdate( 'y-m-d H:i:s' );
        } else {
            // Format date entered by user
            $payment_date = date( 'Y-m-d H:i:s', strtotime( $payment_date ) );
        }

        global $current_user;
        $user_id   = 0;
        $user_name = 'System';
        if ( $current_user && $user_data = get_userdata( $current_user->ID ) ) {
            $user_id   = $current_user->ID;
            $user_name = $user_data->display_name;
        }

        $lead['payment_status'] = $payment_status;
        $lead['payment_amount'] = $payment_amount;
        $lead['payment_date']   = $payment_date;
        $lead['transaction_id'] = $payment_transaction;

        // If payment status does not equal approved/paid or the lead has already been fulfilled, do not continue with fulfillment
        if (  ( $payment_status == 'Approved' || $payment_status == 'Paid' ) && !$lead['is_fulfilled'] ) {
            $action['id']             = $payment_transaction;
            $action['type']           = 'complete_payment';
            $action['transaction_id'] = $payment_transaction;
            $action['amount']         = $payment_amount;
            $action['entry_id']       = $lead['id'];

            $this->complete_payment( $lead, $action );
            $this->fulfill_order( $lead, $payment_transaction, $payment_amount );
        }
        // Update lead, add a note
        GFAPI::update_entry( $lead );
        GFFormsModel::add_note( $lead['id'], $user_id, $user_name, sprintf( __( 'Payment information was manually updated. Status: %s. Amount: %s. Transaction Id: %s. Date: %s', 'gravityformspaysubs' ), $lead['payment_status'], GFCommon::to_money( $lead['payment_amount'], $lead['currency'] ), $payment_transaction, $lead['payment_date'] ) );
    }

    public function fulfill_order( &$entry, $transaction_id, $amount, $feed = null )
    {
        if ( !$feed ) {
            $feed = $this->get_payment_feed( $entry );
        }

        $form = GFFormsModel::get_form_meta( $entry['form_id'] );
        if ( rgars( $feed, 'meta/delayPost' ) ) {
            $this->log_debug( __METHOD__ . '(): Creating post.' );
            $entry['post_id'] = GFFormsModel::create_post( $form, $entry );
            $this->log_debug( __METHOD__ . '(): Post created.' );
        }

        // Sending notifications
        // $notifications = rgars($feed, 'meta/selectedNotifications');
        // GFCommon::send_notifications($notifications, $form, $entry, true, 'form_submission');
        GFAPI::send_notifications( $form, $entry, 'form_submission' );

        do_action( 'gform_paysubs_fulfillment', $entry, $feed, $transaction_id, $amount );
        if ( has_filter( 'gform_paysubs_fulfillment' ) ) {
            $this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_paysubs_fulfillment.' );
        }
    }

    public function paysubs_fulfillment( $entry, $paysubs_config, $transaction_id, $amount )
    {
        // No need to do anything for paysubs when it runs this function, ignore
        return false;
    }

    //------ FOR BACKWARDS COMPATIBILITY ----------------------//
    // Change data when upgrading from legacy paysubs
    public function upgrade( $previous_version )
    {

        $previous_is_pre_addon_framework = version_compare( $previous_version, '1.0', '<' );

        if ( $previous_is_pre_addon_framework ) {
            // Copy plugin settings
            $this->copy_settings();

            // Copy existing feeds to new table
            $this->copy_feeds();

            // Copy existing paysubs transactions to new table
            $this->copy_transactions();

            // Updating payment_gateway entry meta to 'gravityformspaysubs' from 'paysubs'
            $this->update_payment_gateway();

            // Updating entry status from 'Approved' to 'Paid'
            $this->update_lead();
        }
    }

    public function update_feed_id( $old_feed_id, $new_feed_id )
    {
        global $wpdb;
        $sql = $wpdb->prepare( "UPDATE {$wpdb->prefix}rg_lead_meta SET meta_value=%s WHERE meta_key='paysubs_feed_id' AND meta_value=%s", $new_feed_id, $old_feed_id );
        $wpdb->query( $sql );
    }

    public function add_legacy_meta( $new_meta, $old_feed )
    {
        $known_meta_keys = array(
            'email', 'mode', 'type', 'style', 'continue_text', 'cancel_url', 'disable_note', 'disable_shipping', 'recurring_amount_field', 'recurring_times',
            'recurring_retry', 'billing_cycle_number', 'billing_cycle_type', 'trial_period_enabled', 'trial_amount', 'trial_period_number', 'trial_period_type', 'delay_post',
            'update_post_action', 'delay_notifications', 'selected_notifications', 'paysubs_conditional_enabled', 'paysubs_conditional_field_id',
            'paysubs_conditional_operator', 'paysubs_conditional_value', 'customer_fields',
        );

        foreach ( $old_feed['meta'] as $key => $value ) {
            if ( !in_array( $key, $known_meta_keys ) ) {
                $new_meta[$key] = $value;
            }
        }

        return $new_meta;
    }

    public function update_payment_gateway()
    {
        global $wpdb;
        $sql = $wpdb->prepare( "UPDATE {$wpdb->prefix}rg_lead_meta SET meta_value=%s WHERE meta_key='payment_gateway' AND meta_value='paysubs'", $this->_slug );
        $wpdb->query( $sql );
    }

    public function update_lead()
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rg_lead
             SET payment_status='Paid', payment_method='PaySubs'
             WHERE payment_status='Approved'
                    AND ID IN (
                        SELECT lead_id FROM {$wpdb->prefix}rg_lead_meta WHERE meta_key='payment_gateway' AND meta_value=%s
                    )", $this->_slug );

        $wpdb->query( $sql );
    }

    public function copy_settings()
    {
        // Copy plugin settings
        $old_settings = get_option( 'gf_paysubs_configured' );
        $new_settings = array( 'gf_paysubs_configured' => $old_settings );
        $this->update_plugin_settings( $new_settings );
    }

    public function copy_feeds()
    {
        // Get feeds
        $old_feeds = $this->get_old_feeds();

        if ( $old_feeds ) {
            $counter = 1;
            foreach ( $old_feeds as $old_feed ) {
                $feed_name       = 'Feed ' . $counter;
                $form_id         = $old_feed['form_id'];
                $is_active       = $old_feed['is_active'];
                $customer_fields = $old_feed['meta']['customer_fields'];

                $new_meta = array(
                    'feedName'                             => $feed_name,
                    'PaySubsTerminalID'                    => rgar( $old_feed['meta'], 'PaySubsTerminalID' ),
                    'PaySubsTitle'                         => rgar( $old_feed['meta'], 'PaySubsTitle' ),
                    'PaySubsDescription'                   => rgar( $old_feed['meta'], 'PaySubsDescription' ),
                    'PaySubsPersonalAuthenticationMessage' => rgar( $old_feed['meta'], 'PaySubsPersonalAuthenticationMessage' ),
                    'useCustomConfirmationPage'            => rgar( $old_feed['meta'], 'useCustomConfirmationPage' ),
                    'successPageUrl'                       => rgar( $old_feed['meta'], 'successPageUrl' ),
                    'failedPageUrl'                        => rgar( $old_feed['meta'], 'failedPageUrl' ),
                    'mode'                                 => rgar( $old_feed['meta'], 'mode' ),
                    'transactionType'                      => rgar( $old_feed['meta'], 'type' ),
                    'type'                                 => rgar( $old_feed['meta'], 'type' ), // For backwards compatibility of the delayed payment feature
                    'pageStyle'                            => rgar( $old_feed['meta'], 'style' ),
                    'continueText'                         => rgar( $old_feed['meta'], 'continue_text' ),
                    'cancelUrl'                            => rgar( $old_feed['meta'], 'cancel_url' ),
                    'disableNote'                          => rgar( $old_feed['meta'], 'disable_note' ),
                    'disableShipping'                      => rgar( $old_feed['meta'], 'disable_shipping' ),
                    'recurringAmount'                      => rgar( $old_feed['meta'], 'recurring_amount_field' ) == 'all' ? 'form_total' : rgar( $old_feed['meta'], 'recurring_amount_field' ),
                    'recurring_amount_field'               => rgar( $old_feed['meta'], 'recurring_amount_field' ), // For backwards compatibility of the delayed payment feature
                    'recurringTimes'                       => rgar( $old_feed['meta'], 'recurring_times' ),
                    'recurringRetry'                       => rgar( $old_feed['meta'], 'recurring_retry' ),
//                    'paymentAmount' => 'form_total',
                    'billingCycle_length'                  => rgar( $old_feed['meta'], 'billing_cycle_number' ),
                    'billingCycle_unit'                    => $this->convert_interval( rgar( $old_feed['meta'], 'billing_cycle_type' ), 'text' ),
                    'trial_enabled'                        => rgar( $old_feed['meta'], 'trial_period_enabled' ),
                    'trial_product'                        => 'enter_amount',
                    'trial_amount'                         => rgar( $old_feed['meta'], 'trial_amount' ),
                    'trialPeriod_length'                   => rgar( $old_feed['meta'], 'trial_period_number' ),
                    'trialPeriod_unit'                     => $this->convert_interval( rgar( $old_feed['meta'], 'trial_period_type' ), 'text' ),
                    'delayPost'                            => rgar( $old_feed['meta'], 'delay_post' ),
                    'change_post_status'                   => rgar( $old_feed['meta'], 'update_post_action' ) ? '1' : '0',
                    'update_post_action'                   => rgar( $old_feed['meta'], 'update_post_action' ),
                    'delayNotification'                    => rgar( $old_feed['meta'], 'delay_notifications' ),
                    'selectedNotifications'                => rgar( $old_feed['meta'], 'selected_notifications' ),
                    'billingInformation_firstName'         => rgar( $customer_fields, 'first_name' ),
                    'billingInformation_lastName'          => rgar( $customer_fields, 'last_name' ),
                    'billingInformation_email'             => rgar( $customer_fields, 'email' ),
                    'billingInformation_address'           => rgar( $customer_fields, 'address1' ),
                    'billingInformation_address2'          => rgar( $customer_fields, 'address2' ),
                    'billingInformation_city'              => rgar( $customer_fields, 'city' ),
                    'billingInformation_state'             => rgar( $customer_fields, 'state' ),
                    'billingInformation_zip'               => rgar( $customer_fields, 'zip' ),
                    'billingInformation_country'           => rgar( $customer_fields, 'country' ),
                );

                $new_meta = $this->add_legacy_meta( $new_meta, $old_feed );

                // Add conditional logic
                $conditional_enabled = rgar( $old_feed['meta'], 'paysubs_conditional_enabled' );
                if ( $conditional_enabled ) {
                    $new_meta['feed_condition_conditional_logic']        = 1;
                    $new_meta['feed_condition_conditional_logic_object'] = array(
                        'conditionalLogic' => array(
                            'actionType' => 'show',
                            'logicType'  => 'all',
                            'rules'      => array(
                                array(
                                    'fieldId'  => rgar( $old_feed['meta'], 'paysubs_conditional_field_id' ),
                                    'operator' => rgar( $old_feed['meta'], 'paysubs_conditional_operator' ),
                                    'value'    => rgar( $old_feed['meta'], 'paysubs_conditional_value' ),
                                ),
                            ),
                        ),
                    );
                } else {
                    $new_meta['feed_condition_conditional_logic'] = 0;
                }

                $new_feed_id = $this->insert_feed( $form_id, $is_active, $new_meta );
                $this->update_feed_id( $old_feed['id'], $new_feed_id );

                $counter++;
            }
        }
    }

    public function copy_transactions()
    {
        // Copy transactions from the paysubs transaction table to the add payment transaction table
        global $wpdb;
        $old_table_name = $this->get_old_transaction_table_name();
        $this->log_debug( __METHOD__ . '(): Copying old PaySubs transactions into new table structure.' );

        $new_table_name = $this->get_new_transaction_table_name();

        $sql = "INSERT INTO {$new_table_name} (lead_id, transaction_type, transaction_id, is_recurring, amount, date_created)
                    SELECT entry_id, transaction_type, transaction_id, is_renewal, amount, date_created FROM {$old_table_name}";

        $wpdb->query( $sql );

        $this->log_debug( __METHOD__ . "(): transactions: {$wpdb->rows_affected} rows were added." );
    }

    public function get_old_transaction_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'rg_paysubs_transaction';
    }

    public function get_new_transaction_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'gf_addon_payment_transaction';
    }

    public function get_old_feeds()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rg_paysubs';

        $form_table_name = GFFormsModel::get_form_table_name();
        $sql             = "SELECT s.id, s.is_active, s.form_id, s.meta, f.title as form_title
                    FROM {$table_name} s
                    INNER JOIN {$form_table_name} f ON s.form_id = f.id";

        $this->log_debug( __METHOD__ . "(): getting old feeds: {$sql}" );

        $results = $wpdb->get_results( $sql, ARRAY_A );

        $this->log_debug( __METHOD__ . "(): error?: {$wpdb->last_error}" );

        $count = sizeof( $results );

        $this->log_debug( __METHOD__ . "(): count: {$count}" );

        for ( $i = 0; $i < $count; $i++ ) {
            $results[$i]['meta'] = maybe_unserialize( $results[$i]['meta'] );
        }

        return $results;
    }

    // This function kept static for backwards compatibility
    public static function get_config_by_entry( $entry )
    {
        $paysubs = PaySubs::get_instance();

        $feed = $paysubs->get_payment_feed( $entry );

        if ( empty( $feed ) ) {
            return false;
        }

        return $feed['addon_slug'] == $paysubs->_slug ? $feed : false;
    }

    // This function kept static for backwards compatibility
    // This needs to be here until all add-ons are on the framework, otherwise they look for this function
    public static function get_config( $form_id )
    {
        $paysubs = PaySubs::get_instance();
        $feed    = $paysubs->get_feeds( $form_id );

        // Ignore ITN messages from forms that are no longer configured with the PaySubs add-on
        if ( !$feed ) {
            return false;
        }

        return $feed[0]; // Only one feed per form is supported (left for backwards compatibility)
    }

    //------------------------------------------------------
}
