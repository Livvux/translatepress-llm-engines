<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_LLM_Translate {

    protected $loader;

    public function __construct() {
        $trp          = TRP_Translate_Press::get_trp_instance();
        $this->loader = $trp->get_component( 'loader' );

        $this->loader->add_filter( 'trp_machine_translation_engines', $this, 'add_engines', 10, 1 );
        $this->loader->add_filter( 'trp_automatic_translation_engines_classes', $this, 'register_engine_classes', 10, 1 );
        $this->loader->add_action( 'trp_machine_translation_extra_settings_middle', $this, 'add_settings', 10, 1 );
        $this->loader->add_filter( 'trp_machine_translation_sanitize_settings', $this, 'sanitize_settings', 10, 2 );
        $this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_admin_scripts', 99, 1 );
        $this->loader->add_action( 'wp_ajax_trp_llm_fetch_models', $this, 'ajax_fetch_models' );
        $this->loader->add_action( 'admin_head', $this, 'hide_tp_ai_upsells' );
        $this->loader->add_action( 'admin_init', $this, 'suppress_tp_ai_notices', 5 );

        require_once TRP_LLM_ENGINES_PLUGIN_DIR . 'includes/class-openai-machine-translator.php';
        require_once TRP_LLM_ENGINES_PLUGIN_DIR . 'includes/class-anthropic-machine-translator.php';
        require_once TRP_LLM_ENGINES_PLUGIN_DIR . 'includes/class-openrouter-machine-translator.php';
        require_once TRP_LLM_ENGINES_PLUGIN_DIR . 'includes/class-deepseek-machine-translator.php';
    }

    public function enqueue_admin_scripts( $hook ) {
        if ( $hook === 'admin_page_trp_machine_translation' ) {
            wp_enqueue_script(
                'trp-llm-engines-settings',
                TRP_LLM_ENGINES_PLUGIN_URL . 'assets/js/trp-llm-engines-settings.js',
                array( 'jquery' ),
                TRP_LLM_ENGINES_VERSION,
                true
            );

            wp_localize_script( 'trp-llm-engines-settings', 'trp_llm_engines', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'trp_llm_fetch_models' ),
                'i18n'     => array(
                    'loading'       => __( 'Loading models...', 'translatepress-llm-engines' ),
                    'error'         => __( 'Error loading models', 'translatepress-llm-engines' ),
                    'select_model'  => __( 'Select a model', 'translatepress-llm-engines' ),
                    'enter_api_key' => __( 'Enter API key first', 'translatepress-llm-engines' ),
                    'refresh'       => __( 'Refresh Models', 'translatepress-llm-engines' ),
                ),
            ) );
        }
    }

    public function ajax_fetch_models() {
        check_ajax_referer( 'trp_llm_fetch_models', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'translatepress-llm-engines' ) ) );
        }

        $provider = isset( $_POST['provider'] ) ? sanitize_text_field( $_POST['provider'] ) : '';
        $api_key  = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';

        if ( empty( $provider ) ) {
            wp_send_json_error( array( 'message' => __( 'Provider is required.', 'translatepress-llm-engines' ) ) );
        }

        $models = array();

        switch ( $provider ) {
            case 'openai':
                if ( empty( $api_key ) ) {
                    wp_send_json_error( array( 'message' => __( 'API key is required for OpenAI.', 'translatepress-llm-engines' ) ) );
                }
                $models = TRP_OpenAI_Machine_Translator::get_available_models( $api_key );
                break;

            case 'anthropic':
                if ( empty( $api_key ) ) {
                    wp_send_json_error( array( 'message' => __( 'API key is required for Anthropic.', 'translatepress-llm-engines' ) ) );
                }
                $models = TRP_Anthropic_Machine_Translator::get_available_models( $api_key );
                break;

            case 'openrouter':
                $models = TRP_OpenRouter_Machine_Translator::get_available_models( $api_key );
                break;

            default:
                wp_send_json_error( array( 'message' => __( 'Invalid provider.', 'translatepress-llm-engines' ) ) );
        }

        if ( isset( $models['error'] ) ) {
            wp_send_json_error( array( 'message' => $models['error'] ) );
        }

        wp_send_json_success( array( 'models' => $models ) );
    }

    public function add_engines( $engines ) {
        $engines[] = array(
            'value' => 'openai',
            'label' => __( 'OpenAI (GPT)', 'translatepress-llm-engines' )
        );
        $engines[] = array(
            'value' => 'anthropic',
            'label' => __( 'Anthropic (Claude)', 'translatepress-llm-engines' )
        );
        $engines[] = array(
            'value' => 'openrouter',
            'label' => __( 'OpenRouter', 'translatepress-llm-engines' )
        );
        $engines[] = array(
            'value' => 'deepseek',
            'label' => __( 'DeepSeek', 'translatepress-llm-engines' )
        );

        return $engines;
    }

    public function register_engine_classes( $engines ) {
        $engines['openai']     = 'TRP_OpenAI_Machine_Translator';
        $engines['anthropic']  = 'TRP_Anthropic_Machine_Translator';
        $engines['openrouter'] = 'TRP_OpenRouter_Machine_Translator';
        $engines['deepseek']   = 'TRP_DeepSeek_Machine_Translator';

        return $engines;
    }

    public function add_settings( $settings ) {
        $trp                = TRP_Translate_Press::get_trp_instance();
        $machine_translator = $trp->get_component( 'machine_translator' );
        $translation_engine = isset( $settings['translation-engine'] ) ? $settings['translation-engine'] : '';

        $this->render_openai_settings( $settings, $translation_engine, $machine_translator );
        $this->render_anthropic_settings( $settings, $translation_engine, $machine_translator );
        $this->render_openrouter_settings( $settings, $translation_engine, $machine_translator );
        $this->render_deepseek_settings( $settings, $translation_engine, $machine_translator );
    }

    private function render_openai_settings( $settings, $translation_engine, $machine_translator ) {
        $show_errors   = false;
        $error_message = '';

        if ( 'openai' === $translation_engine && method_exists( $machine_translator, 'check_api_key_validity' ) ) {
            $api_check = $machine_translator->check_api_key_validity();
            if ( isset( $api_check ) && true === $api_check['error'] ) {
                $error_message = $api_check['message'];
                $show_errors   = true;
            }
        }

        $text_input_classes = array( 'trp-text-input' );
        if ( $show_errors && 'openai' === $translation_engine ) {
            $text_input_classes[] = 'trp-text-input-error';
        }
        ?>
        <div class="trp-engine trp-automatic-translation-engine__container" id="openai">
            <div class="trp-llm-settings__container">
                <span class="trp-primary-text-bold"><?php esc_html_e( 'OpenAI Model', 'translatepress-llm-engines' ); ?></span>
                <div class="trp-select-wrapper">
                    <select id="trp-openai-model" class="trp-select" name="trp_machine_translation_settings[openai-model]">
                        <?php
                        $models = array(
                            'gpt-4o-mini'  => 'GPT-4o Mini (Recommended)',
                            'gpt-4o'       => 'GPT-4o',
                            'gpt-4-turbo'  => 'GPT-4 Turbo',
                            'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
                        );
                        $current_model = isset( $settings['openai-model'] ) ? $settings['openai-model'] : 'gpt-4o-mini';
                        if ( ! empty( $current_model ) && ! isset( $models[ $current_model ] ) ) {
                            $models = array( $current_model => $current_model . ' (saved)' ) + $models;
                        }
                        foreach ( $models as $value => $label ) :
                            ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_model, $value ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span class="trp-description-text">
                    <?php esc_html_e( 'Select the OpenAI model to use for translations. GPT-4o Mini offers the best balance of quality and cost.', 'translatepress-llm-engines' ); ?>
                </span>
            </div>

            <div class="trp-llm-settings__container">
                <span class="trp-primary-text-bold"><?php esc_html_e( 'OpenAI API Key', 'translatepress-llm-engines' ); ?></span>
                <div class="trp-automatic-translation-api-key-container">
                    <input type="password"
                           id="trp-openai-api-key"
                           class="<?php echo esc_attr( implode( ' ', $text_input_classes ) ); ?>"
                           name="trp_machine_translation_settings[openai-api-key]"
                           value="<?php echo ! empty( $settings['openai-api-key'] ) ? esc_attr( $settings['openai-api-key'] ) : ''; ?>"
                           placeholder="sk-..." />
                    <?php
                    if ( method_exists( $machine_translator, 'automatic_translation_svg_output' ) && 'openai' === $translation_engine ) {
                        $machine_translator->automatic_translation_svg_output( $show_errors );
                    }
                    ?>
                </div>
                <?php if ( $show_errors && 'openai' === $translation_engine ) : ?>
                    <span class="trp-error-inline trp-settings-error-text">
                        <?php echo wp_kses_post( $error_message ); ?>
                    </span>
                <?php endif; ?>
                <span class="trp-description-text">
                    <?php
                    echo wp_kses(
                        sprintf(
                            __( 'Get your API key from <a href="%s" target="_blank">OpenAI Platform</a>.', 'translatepress-llm-engines' ),
                            'https://platform.openai.com/api-keys'
                        ),
                        array( 'a' => array( 'href' => array(), 'target' => array() ) )
                    );
                    ?>
                </span>
            </div>
        </div>
        <?php
    }

    private function render_anthropic_settings( $settings, $translation_engine, $machine_translator ) {
        $show_errors   = false;
        $error_message = '';

        if ( 'anthropic' === $translation_engine && method_exists( $machine_translator, 'check_api_key_validity' ) ) {
            $api_check = $machine_translator->check_api_key_validity();
            if ( isset( $api_check ) && true === $api_check['error'] ) {
                $error_message = $api_check['message'];
                $show_errors   = true;
            }
        }

        $text_input_classes = array( 'trp-text-input' );
        if ( $show_errors && 'anthropic' === $translation_engine ) {
            $text_input_classes[] = 'trp-text-input-error';
        }
        ?>
        <div class="trp-engine trp-automatic-translation-engine__container" id="anthropic">
            <div class="trp-llm-settings__container">
                <span class="trp-primary-text-bold"><?php esc_html_e( 'Anthropic Model', 'translatepress-llm-engines' ); ?></span>
                <div class="trp-select-wrapper">
                    <select id="trp-anthropic-model" class="trp-select" name="trp_machine_translation_settings[anthropic-model]">
                        <?php
                        $models = array(
                            'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet (Recommended)',
                            'claude-3-5-haiku-20241022'  => 'Claude 3.5 Haiku (Fast)',
                            'claude-3-opus-20240229'     => 'Claude 3 Opus',
                        );
                        $current_model = isset( $settings['anthropic-model'] ) ? $settings['anthropic-model'] : 'claude-3-5-sonnet-20241022';
                        if ( ! empty( $current_model ) && ! isset( $models[ $current_model ] ) ) {
                            $models = array( $current_model => $current_model . ' (saved)' ) + $models;
                        }
                        foreach ( $models as $value => $label ) :
                            ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_model, $value ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span class="trp-description-text">
                    <?php esc_html_e( 'Select the Anthropic Claude model to use for translations.', 'translatepress-llm-engines' ); ?>
                </span>
            </div>

            <div class="trp-llm-settings__container">
                <span class="trp-primary-text-bold"><?php esc_html_e( 'Anthropic API Key', 'translatepress-llm-engines' ); ?></span>
                <div class="trp-automatic-translation-api-key-container">
                    <input type="password"
                           id="trp-anthropic-api-key"
                           class="<?php echo esc_attr( implode( ' ', $text_input_classes ) ); ?>"
                           name="trp_machine_translation_settings[anthropic-api-key]"
                           value="<?php echo ! empty( $settings['anthropic-api-key'] ) ? esc_attr( $settings['anthropic-api-key'] ) : ''; ?>"
                           placeholder="sk-ant-..." />
                    <?php
                    if ( method_exists( $machine_translator, 'automatic_translation_svg_output' ) && 'anthropic' === $translation_engine ) {
                        $machine_translator->automatic_translation_svg_output( $show_errors );
                    }
                    ?>
                </div>
                <?php if ( $show_errors && 'anthropic' === $translation_engine ) : ?>
                    <span class="trp-error-inline trp-settings-error-text">
                        <?php echo wp_kses_post( $error_message ); ?>
                    </span>
                <?php endif; ?>
                <span class="trp-description-text">
                    <?php
                    echo wp_kses(
                        sprintf(
                            __( 'Get your API key from <a href="%s" target="_blank">Anthropic Console</a>.', 'translatepress-llm-engines' ),
                            'https://console.anthropic.com/settings/keys'
                        ),
                        array( 'a' => array( 'href' => array(), 'target' => array() ) )
                    );
                    ?>
                </span>
            </div>
        </div>
        <?php
    }

    private function render_openrouter_settings( $settings, $translation_engine, $machine_translator ) {
        $show_errors   = false;
        $error_message = '';

        if ( 'openrouter' === $translation_engine && method_exists( $machine_translator, 'check_api_key_validity' ) ) {
            $api_check = $machine_translator->check_api_key_validity();
            if ( isset( $api_check ) && true === $api_check['error'] ) {
                $error_message = $api_check['message'];
                $show_errors   = true;
            }
        }

        $text_input_classes = array( 'trp-text-input' );
        if ( $show_errors && 'openrouter' === $translation_engine ) {
            $text_input_classes[] = 'trp-text-input-error';
        }
        ?>
        <div class="trp-engine trp-automatic-translation-engine__container" id="openrouter">
            <div class="trp-llm-settings__container">
                <span class="trp-primary-text-bold"><?php esc_html_e( 'OpenRouter Model', 'translatepress-llm-engines' ); ?></span>
                <div class="trp-select-wrapper">
                    <select id="trp-openrouter-model" class="trp-select" name="trp_machine_translation_settings[openrouter-model]">
                        <?php
                        $models = array(
                            'anthropic/claude-3.5-sonnet'    => 'Claude 3.5 Sonnet (Recommended)',
                            'openai/gpt-4o-mini'             => 'GPT-4o Mini',
                            'openai/gpt-4o'                  => 'GPT-4o',
                            'google/gemini-2.0-flash-exp'    => 'Gemini 2.0 Flash',
                            'google/gemini-pro-1.5'          => 'Gemini Pro 1.5',
                            'meta-llama/llama-3.1-70b-instruct' => 'Llama 3.1 70B',
                            'mistralai/mistral-large'        => 'Mistral Large',
                            'deepseek/deepseek-chat'         => 'DeepSeek Chat',
                        );
                        $current_model = isset( $settings['openrouter-model'] ) ? $settings['openrouter-model'] : 'anthropic/claude-3.5-sonnet';
                        if ( ! empty( $current_model ) && ! isset( $models[ $current_model ] ) ) {
                            $models = array( $current_model => $current_model . ' (saved)' ) + $models;
                        }
                        foreach ( $models as $value => $label ) :
                            ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_model, $value ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span class="trp-description-text">
                    <?php esc_html_e( 'Select the model to use via OpenRouter. You can access multiple AI providers with one API key.', 'translatepress-llm-engines' ); ?>
                </span>
            </div>

            <div class="trp-llm-settings__container">
                <span class="trp-primary-text-bold"><?php esc_html_e( 'OpenRouter API Key', 'translatepress-llm-engines' ); ?></span>
                <div class="trp-automatic-translation-api-key-container">
                    <input type="password"
                           id="trp-openrouter-api-key"
                           class="<?php echo esc_attr( implode( ' ', $text_input_classes ) ); ?>"
                           name="trp_machine_translation_settings[openrouter-api-key]"
                           value="<?php echo ! empty( $settings['openrouter-api-key'] ) ? esc_attr( $settings['openrouter-api-key'] ) : ''; ?>"
                           placeholder="sk-or-..." />
                    <?php
                    if ( method_exists( $machine_translator, 'automatic_translation_svg_output' ) && 'openrouter' === $translation_engine ) {
                        $machine_translator->automatic_translation_svg_output( $show_errors );
                    }
                    ?>
                </div>
                <?php if ( $show_errors && 'openrouter' === $translation_engine ) : ?>
                    <span class="trp-error-inline trp-settings-error-text">
                        <?php echo wp_kses_post( $error_message ); ?>
                    </span>
                <?php endif; ?>
                <span class="trp-description-text">
                    <?php
                    echo wp_kses(
                        sprintf(
                            __( 'Get your API key from <a href="%s" target="_blank">OpenRouter</a>. Access 100+ models with one key.', 'translatepress-llm-engines' ),
                            'https://openrouter.ai/keys'
                        ),
                        array( 'a' => array( 'href' => array(), 'target' => array() ) )
                    );
                    ?>
                </span>
            </div>
        </div>
        <?php
    }

    private function render_deepseek_settings( $settings, $translation_engine, $machine_translator ) {
        $show_errors   = false;
        $error_message = '';

        if ( 'deepseek' === $translation_engine && method_exists( $machine_translator, 'check_api_key_validity' ) ) {
            $api_check = $machine_translator->check_api_key_validity();
            if ( isset( $api_check ) && true === $api_check['error'] ) {
                $error_message = $api_check['message'];
                $show_errors   = true;
            }
        }

        $text_input_classes = array( 'trp-text-input' );
        if ( $show_errors && 'deepseek' === $translation_engine ) {
            $text_input_classes[] = 'trp-text-input-error';
        }
        ?>
        <div class="trp-engine trp-automatic-translation-engine__container" id="deepseek">
            <div class="trp-llm-settings__container">
                <span class="trp-primary-text-bold"><?php esc_html_e( 'DeepSeek Model', 'translatepress-llm-engines' ); ?></span>
                <div class="trp-select-wrapper">
                    <select id="trp-deepseek-model" class="trp-select" name="trp_machine_translation_settings[deepseek-model]">
                        <?php
                        $models = array(
                            'deepseek-chat'     => 'DeepSeek Chat (Recommended - Best Value)',
                            'deepseek-reasoner' => 'DeepSeek Reasoner (R1)',
                        );
                        $current_model = isset( $settings['deepseek-model'] ) ? $settings['deepseek-model'] : 'deepseek-chat';
                        if ( ! empty( $current_model ) && ! isset( $models[ $current_model ] ) ) {
                            $models = array( $current_model => $current_model . ' (saved)' ) + $models;
                        }
                        foreach ( $models as $value => $label ) :
                            ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_model, $value ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span class="trp-description-text">
                    <?php esc_html_e( 'DeepSeek Chat offers excellent translation quality at the lowest cost. ~$0.14/1M input, ~$0.28/1M output tokens.', 'translatepress-llm-engines' ); ?>
                </span>
            </div>

            <div class="trp-llm-settings__container">
                <span class="trp-primary-text-bold"><?php esc_html_e( 'DeepSeek API Key', 'translatepress-llm-engines' ); ?></span>
                <div class="trp-automatic-translation-api-key-container">
                    <input type="password"
                           id="trp-deepseek-api-key"
                           class="<?php echo esc_attr( implode( ' ', $text_input_classes ) ); ?>"
                           name="trp_machine_translation_settings[deepseek-api-key]"
                           value="<?php echo ! empty( $settings['deepseek-api-key'] ) ? esc_attr( $settings['deepseek-api-key'] ) : ''; ?>"
                           placeholder="sk-..." />
                    <?php
                    if ( method_exists( $machine_translator, 'automatic_translation_svg_output' ) && 'deepseek' === $translation_engine ) {
                        $machine_translator->automatic_translation_svg_output( $show_errors );
                    }
                    ?>
                </div>
                <?php if ( $show_errors && 'deepseek' === $translation_engine ) : ?>
                    <span class="trp-error-inline trp-settings-error-text">
                        <?php echo wp_kses_post( $error_message ); ?>
                    </span>
                <?php endif; ?>
                <span class="trp-description-text">
                    <?php
                    echo wp_kses(
                        sprintf(
                            __( 'Get your API key from <a href="%s" target="_blank">DeepSeek Platform</a>.', 'translatepress-llm-engines' ),
                            'https://platform.deepseek.com/api_keys'
                        ),
                        array( 'a' => array( 'href' => array(), 'target' => array() ) )
                    );
                    ?>
                </span>
            </div>
        </div>
        <?php
    }

    public function sanitize_settings( $settings, $mt_settings ) {
        $llm_keys = array(
            'openai-api-key',
            'openai-model',
            'anthropic-api-key',
            'anthropic-model',
            'openrouter-api-key',
            'openrouter-model',
            'deepseek-api-key',
            'deepseek-model',
        );

        foreach ( $llm_keys as $key ) {
            if ( isset( $mt_settings[ $key ] ) && ! empty( $mt_settings[ $key ] ) ) {
                $settings[ $key ] = sanitize_text_field( $mt_settings[ $key ] );
            }
        }

        return $settings;
    }

    public function hide_tp_ai_upsells() {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'trp_' ) === false ) {
            return;
        }
        ?>
        <style id="trp-llm-engines-hide-upsells">
            /* Hide TranslatePress AI upsell on Machine Translation page */
            .tp-ai-upsell,
            .trp-ai-upsell-arrow {
                display: none !important;
            }
            /* Hide "Don't have a TranslatePress AI License Key?" on License page */
            .trp-license-page-upsell-container .trp-settings-container:has(a[href*="ai-free"]) {
                display: none !important;
            }
            /* Hide right sidebar upgrade notice on License page */
            .trp-license-page-upsell-container__right {
                display: none !important;
            }
        </style>
        <?php
    }

    public function suppress_tp_ai_notices() {
        // Suppress TranslatePress AI related admin notices
        add_filter( 'pre_option_trp_dismiss_admin_notification_trp_mtapi_missing_license', '__return_true' );
        add_filter( 'pre_option_trp_dismiss_admin_notification_trp_mtapi_invalid_license', '__return_true' );
        add_filter( 'pre_option_trp_dismiss_admin_notification_trp_low_quota_warning', '__return_true' );
    }
}
