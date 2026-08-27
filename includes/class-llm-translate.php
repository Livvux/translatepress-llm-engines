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

        require_once TRP_LLM_ENGINES_PLUGIN_DIR . 'includes/class-breadcrumb.php';
        require_once TRP_LLM_ENGINES_PLUGIN_DIR . 'includes/class-http-failure-log.php';
        require_once TRP_LLM_ENGINES_PLUGIN_DIR . 'includes/class-request-retry.php';
        require_once TRP_LLM_ENGINES_PLUGIN_DIR . 'includes/class-engine-cooldown.php';
        require_once TRP_LLM_ENGINES_PLUGIN_DIR . 'includes/class-request-shape.php';
        require_once TRP_LLM_ENGINES_PLUGIN_DIR . 'includes/class-chunk-runner.php';
        require_once TRP_LLM_ENGINES_PLUGIN_DIR . 'includes/class-key-verdict.php';
        require_once TRP_LLM_ENGINES_PLUGIN_DIR . 'includes/class-placeholder-guard.php';
        require_once TRP_LLM_ENGINES_PLUGIN_DIR . 'includes/class-response-normalizer.php';
        require_once TRP_LLM_ENGINES_PLUGIN_DIR . 'includes/class-translation-skiplist.php';
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

        // The Refresh Models button has always sent this. Reading it is what
        // turns the button from decoration into a cache bust.
        $force_refresh = ! empty( $_POST['force_refresh'] );

        if ( empty( $provider ) ) {
            wp_send_json_error( array( 'message' => __( 'Provider is required.', 'translatepress-llm-engines' ) ) );
        }

        $models = array();

        switch ( $provider ) {
            case 'openai':
                if ( empty( $api_key ) ) {
                    wp_send_json_error( array( 'message' => __( 'API key is required for OpenAI.', 'translatepress-llm-engines' ) ) );
                }
                $models = TRP_OpenAI_Machine_Translator::get_available_models( $api_key, $force_refresh );
                break;

            case 'anthropic':
                if ( empty( $api_key ) ) {
                    wp_send_json_error( array( 'message' => __( 'API key is required for Anthropic.', 'translatepress-llm-engines' ) ) );
                }
                $models = TRP_Anthropic_Machine_Translator::get_available_models( $api_key, $force_refresh );
                break;

            case 'openrouter':
                $models = TRP_OpenRouter_Machine_Translator::get_available_models( $api_key, $force_refresh );
                break;

            case 'deepseek':
                if ( empty( $api_key ) ) {
                    wp_send_json_error( array( 'message' => __( 'API key is required for DeepSeek.', 'translatepress-llm-engines' ) ) );
                }
                $models = TRP_DeepSeek_Machine_Translator::get_available_models( $api_key, $force_refresh );
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

    /**
     * Engine slug to the class that answers for it.
     *
     * A constant rather than four literals inside register_engine_classes(),
     * because this map is needed in more than one place now and a second copy of
     * it is a second thing to forget.
     */
    const ENGINE_CLASSES = array(
        'openai'     => 'TRP_OpenAI_Machine_Translator',
        'anthropic'  => 'TRP_Anthropic_Machine_Translator',
        'openrouter' => 'TRP_OpenRouter_Machine_Translator',
        'deepseek'   => 'TRP_DeepSeek_Machine_Translator',
    );

    public function register_engine_classes( $engines ) {
        foreach ( self::ENGINE_CLASSES as $slug => $class ) {
            $engines[ $slug ] = $class;
        }

        return $engines;
    }

    public function add_settings( $settings ) {
        // Proof that this plugin's fields were part of the submission. Without it
        // an emptied API key field is indistinguishable from a form that never
        // carried the field, and the two need opposite handling. The vendor uses
        // the same trick for its notification fields.
        printf(
            '<input type="hidden" name="trp_machine_translation_settings[%s]" value="1" />',
            esc_attr( self::FIELDS_MARKER )
        );

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
                            // Only models confirmed absent from OpenAI's deprecation
                            // tables on 2026-08-27. Anything else belongs in the
                            // live list the Refresh Models button fetches, not in a
                            // literal that goes stale without telling anyone.
                            'gpt-4o-mini' => 'GPT-4o Mini (Recommended)',
                            'gpt-4o'      => 'GPT-4o',
                        );
                        $current_model = ! empty( $settings['openai-model'] )
                            ? $settings['openai-model']
                            : TRP_OpenAI_Machine_Translator::DEFAULT_MODEL;
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
                            'claude-haiku-4-5'  => 'Claude Haiku 4.5 (Recommended - Best Value)',
                            'claude-sonnet-5'   => 'Claude Sonnet 5',
                            'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
                            'claude-opus-5'     => 'Claude Opus 5',
                        );
                        $current_model = ! empty( $settings['anthropic-model'] )
                            ? $settings['anthropic-model']
                            : TRP_Anthropic_Machine_Translator::DEFAULT_MODEL;
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
                            'deepseek/deepseek-v4-flash'        => 'DeepSeek V4 Flash (Recommended)',
                            'deepseek/deepseek-v4-pro'          => 'DeepSeek V4 Pro',
                            'google/gemini-2.5-flash-lite'      => 'Gemini 2.5 Flash Lite',
                            'anthropic/claude-haiku-4.5'        => 'Claude Haiku 4.5',
                            'openai/gpt-4o-mini'                => 'GPT-4o Mini',
                            'meta-llama/llama-3.1-70b-instruct' => 'Llama 3.1 70B',
                            'mistralai/mistral-large'           => 'Mistral Large',
                        );
                        $current_model = ! empty( $settings['openrouter-model'] )
                            ? $settings['openrouter-model']
                            : TRP_OpenRouter_Machine_Translator::DEFAULT_MODEL;
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
                            'deepseek-v4-flash' => 'DeepSeek V4 Flash (Recommended - Best Value)',
                            'deepseek-v4-pro'   => 'DeepSeek V4 Pro',
                        );
                        $current_model = ! empty( $settings['deepseek-model'] )
                            ? $settings['deepseek-model']
                            : TRP_DeepSeek_Machine_Translator::DEFAULT_MODEL;
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
                    <?php esc_html_e( 'DeepSeek V4 Flash offers excellent translation quality at the lowest cost. ~$0.07/1M input, ~$0.17/1M output tokens.', 'translatepress-llm-engines' ); ?>
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

    /**
     * Setting keys this plugin owns.
     *
     * The vendor's own sanitiser starts from an empty array and copies only the
     * keys it knows, so a key this filter does not put back is a key that is
     * deleted on save.
     */
    const LLM_SETTING_KEYS = array(
        'openai-api-key',
        'openai-model',
        'anthropic-api-key',
        'anthropic-model',
        'openrouter-api-key',
        'openrouter-model',
        'deepseek-api-key',
        'deepseek-model',
    );

    /**
     * Name of the hidden field that proves our panels were submitted.
     */
    const FIELDS_MARKER = 'llm-engines-fields';

    public function sanitize_settings( $settings, $mt_settings ) {
        $existing = get_option( 'trp_machine_translation_settings', array() );
        $existing = is_array( $existing ) ? $existing : array();

        $settings = self::sanitize_llm_keys(
            is_array( $settings ) ? $settings : array(),
            is_array( $mt_settings ) ? $mt_settings : array(),
            $existing
        );

        self::forget_stale_state( $settings, $existing );

        return $settings;
    }

    /**
     * Drop the state that belonged to a key that is no longer saved.
     *
     * Static and separate from sanitize_settings() for the same reason
     * sanitize_llm_keys() is: the constructor needs a live TranslatePress
     * instance, so anything left inside the instance method can only be tested
     * by reading the source, and reading the source is what let this call go
     * missing once already.
     *
     * @param array $settings Settings as they will be saved.
     * @param array $existing Settings as they were before the save.
     *
     * @return void
     */
    public static function forget_stale_state( array $settings, array $existing ) {
        // A cooldown outlives the problem that started it. Someone who has just
        // pasted a new key has fixed the thing the cooldown is waiting out, and
        // should not have to wait a quarter of an hour to find out.
        foreach ( self::ENGINE_CLASSES as $engine => $class ) {
            $key = $engine . '-api-key';

            $before = isset( $existing[ $key ] ) ? $existing[ $key ] : '';
            $after  = isset( $settings[ $key ] ) ? $settings[ $key ] : '';

            if ( $before !== $after ) {
                TRP_LLM_Engine_Cooldown::clear( $engine );
                delete_transient( TRP_LLM_Key_Verdict::key( $engine, $before ) );

                // The model list is cached for a day and keyed by the old key, so
                // without this a new key kept serving the previous account's
                // catalogue for up to twenty four hours. Keying the transient by
                // the key was supposed to prevent exactly that and only worked in
                // one direction, because nothing ever removed the old entry.
                delete_transient( $class::models_transient_key( $before ) );
            }
        }
    }

    /**
     * Decide what each of this plugin's keys is worth after a save.
     *
     * The previous rule was a single non-empty check, which made an API key
     * impossible to remove: clearing the field submitted an empty string, the
     * check rejected it, and the old key was written back. A key that cannot be
     * revoked from the screen that set it is the wrong default for a credential.
     *
     * Simply dropping the non-empty check is not enough either, because this
     * filter also runs on saves from screens that do not render our fields at
     * all, and there an absent key must mean "leave it alone" rather than
     * "delete it". The marker separates the two cases.
     *
     * Static and dependency free so a contract can exercise it without a
     * TranslatePress instance, which the constructor of this class requires.
     *
     * @param array $settings    Settings the vendor has assembled so far.
     * @param array $mt_settings Raw submitted values.
     * @param array $existing    Currently stored settings.
     *
     * @return array
     */
    public static function sanitize_llm_keys( array $settings, array $mt_settings, array $existing ) {
        $submitted = ! empty( $mt_settings[ self::FIELDS_MARKER ] );

        foreach ( self::LLM_SETTING_KEYS as $key ) {
            if ( $submitted && array_key_exists( $key, $mt_settings ) ) {
                $settings[ $key ] = sanitize_text_field( $mt_settings[ $key ] );
                continue;
            }

            if ( array_key_exists( $key, $existing ) ) {
                $settings[ $key ] = $existing[ $key ];
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
