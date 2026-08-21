jQuery(document).on('trpInitFieldToggler', function() {
    var openaiKey = TRP_Field_Toggler();
    openaiKey.init('.trp-translation-engine', '#trp-openai-api-key', 'openai');

    var openaiModel = TRP_Field_Toggler();
    openaiModel.init('.trp-translation-engine', '#trp-openai-model', 'openai');

    var anthropicKey = TRP_Field_Toggler();
    anthropicKey.init('.trp-translation-engine', '#trp-anthropic-api-key', 'anthropic');

    var anthropicModel = TRP_Field_Toggler();
    anthropicModel.init('.trp-translation-engine', '#trp-anthropic-model', 'anthropic');

    var openrouterKey = TRP_Field_Toggler();
    openrouterKey.init('.trp-translation-engine', '#trp-openrouter-api-key', 'openrouter');

    var openrouterModel = TRP_Field_Toggler();
    openrouterModel.init('.trp-translation-engine', '#trp-openrouter-model', 'openrouter');
});

(function($) {
    'use strict';

    var TRP_LLM_Models = {
        // Options as the server rendered them, captured before anything replaces
        // them. This used to be a hardcoded object duplicating the PHP model
        // lists, which had drifted from them and could not know about a custom
        // model the user had saved.
        serverOptions: {},

        init: function() {
            this.captureServerOptions();
            this.bindEvents();
            this.addRefreshButtons();
        },

        captureServerOptions: function() {
            var self = this;

            ['openai', 'anthropic', 'openrouter', 'deepseek'].forEach(function(provider) {
                var $select = $('#trp-' + provider + '-model');
                if ($select.length) {
                    self.serverOptions[provider] = $select.html();
                }
            });
        },

        bindEvents: function() {
            var self = this;

            $('#trp-openai-api-key').on('blur', function() {
                self.fetchModels('openai', $(this).val(), '#trp-openai-model');
            });

            $('#trp-anthropic-api-key').on('blur', function() {
                self.fetchModels('anthropic', $(this).val(), '#trp-anthropic-model');
            });

            $('#trp-openrouter-api-key').on('blur', function() {
                self.fetchModels('openrouter', $(this).val(), '#trp-openrouter-model');
            });

            $(document).on('click', '.trp-llm-refresh-models', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var provider = $btn.data('provider');
                var apiKeySelector = '#trp-' + provider + '-api-key';
                var modelSelector = '#trp-' + provider + '-model';
                var apiKey = $(apiKeySelector).val();

                self.fetchModels(provider, apiKey, modelSelector, true);
            });
        },

        addRefreshButtons: function() {
            var i18n = window.trp_llm_engines ? window.trp_llm_engines.i18n : { refresh: 'Refresh Models' };
            var providers = ['openai', 'anthropic', 'openrouter'];

            providers.forEach(function(provider) {
                var $select = $('#trp-' + provider + '-model');
                if ($select.length && !$select.siblings('.trp-llm-refresh-models').length) {
                    $select.after(
                        '<button type="button" class="button trp-llm-refresh-models" data-provider="' + provider + '" style="margin-left: 10px;">' +
                        '<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> ' + i18n.refresh +
                        '</button>'
                    );
                }
            });
        },

        fetchModels: function(provider, apiKey, modelSelector, forceRefresh) {
            var self = this;
            var $select = $(modelSelector);
            var $refreshBtn = $('.trp-llm-refresh-models[data-provider="' + provider + '"]');
            var currentValue = $select.val();
            var i18n = window.trp_llm_engines ? window.trp_llm_engines.i18n : {
                loading: 'Loading models...',
                error: 'Error loading models',
                select_model: 'Select a model',
                enter_api_key: 'Enter API key first'
            };

            if (provider !== 'openrouter' && !apiKey) {
                return;
            }

            $select.prop('disabled', true);
            $refreshBtn.prop('disabled', true).find('.dashicons').addClass('spin');

            var $loadingOption = $('<option>').val('').text(i18n.loading);
            $select.empty().append($loadingOption);

            $.ajax({
                url: window.trp_llm_engines ? window.trp_llm_engines.ajax_url : ajaxurl,
                type: 'POST',
                data: {
                    action: 'trp_llm_fetch_models',
                    nonce: window.trp_llm_engines ? window.trp_llm_engines.nonce : '',
                    provider: provider,
                    api_key: apiKey,
                    force_refresh: forceRefresh ? 1 : 0
                },
                success: function(response) {
                    $select.empty();

                    if (response.success && response.data.models) {
                        var models = response.data.models;
                        var hasModels = false;

                        $.each(models, function(modelId, modelName) {
                            hasModels = true;
                            var $option = $('<option>').val(modelId).text(modelName);
                            if (modelId === currentValue) {
                                $option.prop('selected', true);
                            }
                            $select.append($option);
                        });

                        if (!hasModels) {
                            $select.append($('<option>').val('').text(i18n.error));
                        }
                    } else {
                        var errorMsg = response.data && response.data.message ? response.data.message : i18n.error;
                        $select.append($('<option>').val('').text(errorMsg));
                        self.restoreDefaultModels(provider, $select, currentValue);
                    }
                },
                error: function() {
                    $select.empty().append($('<option>').val('').text(i18n.error));
                    self.restoreDefaultModels(provider, $select, currentValue);
                },
                complete: function() {
                    $select.prop('disabled', false);
                    $refreshBtn.prop('disabled', false).find('.dashicons').removeClass('spin');
                }
            });
        },

        restoreDefaultModels: function(provider, $select, currentValue) {
            var markup = this.serverOptions[provider];

            if (!markup) {
                return;
            }

            $select.html(markup);

            if (currentValue) {
                $select.val(currentValue);
            }
        }
    };

    $(document).ready(function() {
        if ($('#trp-openai-api-key').length || $('#trp-anthropic-api-key').length || $('#trp-openrouter-api-key').length) {
            TRP_LLM_Models.init();
        }
    });

    var style = document.createElement('style');
    style.textContent = '.trp-llm-refresh-models .dashicons.spin { animation: trp-spin 1s linear infinite; } @keyframes trp-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
    document.head.appendChild(style);

})(jQuery);
