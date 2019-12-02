<?php

/**
 * Plugin Name: Gravity Forms PaySubs Add-On
 * Plugin URI: https://github.com/PayGate/PaySubs_Gravity_Forms
 * Description: Integrates Gravity Forms with PaySubs, a South African payment gateway.
 * Version: 1.0.0
 * Tested: 5.3.0
 * Author: PayGate (Pty) Ltd
 * Author URI: https://www.paygate.co.za/
 * Developer: App Inlet (Pty) Ltd
 * Developer URI: https://www.appinlet.com/
 * Text Domain: gravityformspaysubs
 * Domain Path: /languages
 * 
 * Copyright: © 2019 PayGate (Pty) Ltd.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

add_action( 'plugins_loaded', 'paysubs_init' );

/**
 * Initialize the gateway.
 *
 * @since 1.0.0
 */

function paysubs_init()
{
     /**
     * Auto updates from GIT
     *
     * @since 2.2.9
     *
     */

    require_once 'updater.class.php';

    if ( is_admin() ) {
        // note the use of is_admin() to double check that this is happening in the admin

        $config = array(
            'slug'               => plugin_basename( __FILE__ ),
            'proper_folder_name' => 'gravityformspaysubs',
            'api_url'            => 'https://api.github.com/repos/PayGate/PaySubs_Gravity_Forms',
            'raw_url'            => 'https://raw.github.com/PayGate/PaySubs_Gravity_Forms/master',
            'github_url'         => 'https://github.com/PayGate/PaySubs_Gravity_Forms',
            'zip_url'            => 'https://github.com/PayGate/PaySubs_Gravity_Forms/archive/master.zip',
            'homepage'           => 'https://github.com/PayGate/PaySubs_Gravity_Forms',
            'sslverify'          => true,
            'requires'           => '4.0',
            'tested'             => '5.3.0',
            'readme'             => 'README.md',
            'access_token'       => '',
        );

        new WP_GitHub_Updater_PS( $config );

    }
}

add_action( 'gform_loaded', array( 'GF_PaySubs_Main', 'load' ), 5 );

class GF_PaySubs_Main
{

    public static function load()
    {
        if ( !method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
            return;
        }

        require_once 'paysubs-gf-class.php';
        GFAddOn::register( 'GFPaySubs' );
    }

}

/**
 * Encrypt and decrypt
 * @param string $string string to be encrypted/decrypted
 * @param string $action what to do with this? e for encrypt, d for decrypt
 */
function PaySubs_encryption( $string, $action = 'e' )
{
    // you may change these values to your own
    $secret_key = AUTH_SALT;
    $secret_iv  = NONCE_SALT;

    $output         = false;
    $encrypt_method = "AES-256-CBC";
    $key            = hash( 'sha256', $secret_key );
    $iv             = substr( hash( 'sha256', $secret_iv ), 0, 16 );

    if ( $action == 'e' ) {
        $output = base64_encode( openssl_encrypt( $string, $encrypt_method, $key, 0, $iv ) );
    } else if ( $action == 'd' ) {
        $output = openssl_decrypt( base64_decode( $string ), $encrypt_method, $key, 0, $iv );
    }

    return $output;
}
