<?php
/**
 * Plugin Name: TranslatePress - LLM Translation Engines
 * Plugin URI: https://github.com/Livvux/translatepress-llm-engines
 * Description: Adds OpenAI, Anthropic (Claude), OpenRouter, and DeepSeek as automatic translation engines for TranslatePress. Use your own API keys for AI-powered translations.
 * Version: 1.1.0
 * Author: Livvux
 * Author URI: https://livvux.com/
 * License: GPL2
 * Text Domain: translatepress-llm-engines
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * == Copyright ==
 * Copyright 2024-2026 Livvux
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Initialize the LLM Translation Engines addon
 */
function trp_llm_engines_init() {
    // Define plugin constants
    define( 'TRP_LLM_ENGINES_VERSION', '1.1.0' );
    define( 'TRP_LLM_ENGINES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
    define( 'TRP_LLM_ENGINES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

    // Check if TranslatePress is active
    if ( ! class_exists( 'TRP_Translate_Press' ) ) {
        add_action( 'admin_notices', 'trp_llm_engines_missing_translatepress_notice' );
        return;
    }

    // Load the main class
    require_once TRP_LLM_ENGINES_PLUGIN_DIR . 'includes/class-llm-translate.php';

    // Initialize the addon
    new TRP_LLM_Translate();
}
add_action( 'plugins_loaded', 'trp_llm_engines_init', 0 );

/**
 * Admin notice when TranslatePress is not active
 */
function trp_llm_engines_missing_translatepress_notice() {
    ?>
    <div class="notice notice-error">
        <p>
            <?php
            echo wp_kses(
                sprintf(
                    __( '<strong>TranslatePress - LLM Translation Engines</strong> requires <a href="%s" target="_blank">TranslatePress</a> to be installed and activated.', 'translatepress-llm-engines' ),
                    'https://wordpress.org/plugins/translatepress-multilingual/'
                ),
                array( 'strong' => array(), 'a' => array( 'href' => array(), 'target' => array() ) )
            );
            ?>
        </p>
    </div>
    <?php
}

/**
 * Plugin activation hook
 */
function trp_llm_engines_activate() {
    // Activation tasks if needed
}
register_activation_hook( __FILE__, 'trp_llm_engines_activate' );

/**
 * Plugin deactivation hook
 */
function trp_llm_engines_deactivate() {
    // Deactivation tasks if needed
}
register_deactivation_hook( __FILE__, 'trp_llm_engines_deactivate' );
