// One list, read by the toggler, the blur handlers, the option capture and the
// refresh buttons. DeepSeek was registered as an engine and rendered as a
// settings panel but was missing from some of those lists and not others, so
// its fields did not follow the engine selector at all.
var TRP_LLM_PROVIDERS = ['openai', 'anthropic', 'openrouter', 'deepseek'];

jQuery(document).on('trpInitFieldToggler', function() {
    TRP_LLM_PROVIDERS.forEach(function(provider) {
        ['api-key', 'model'].forEach(function(field) {
            TRP_Field_Toggler().init(
                '.trp-translation-engine',
                '#trp-' + provider + '-' + field,
                provider
            );
        });
    });
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

            TRP_LLM_PROVIDERS.forEach(function(provider) {
                var $select = $('#trp-' + provider + '-model');
                if ($select.length) {
                    self.serverOptions[provider] = $select.html();
                }
            });
        },

        bindEvents: function() {
            var self = this;

            TRP_LLM_PROVIDERS.forEach(function(provider) {
                $('#trp-' + provider + '-api-key').on('blur', function() {
                    self.fetchModels(provider, $(this).val(), '#trp-' + provider + '-model');
                });
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
            TRP_LLM_PROVIDERS.forEach(function(provider) {
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
                        var matched = false;

                        $.each(models, function(modelId, modelName) {
                            hasModels = true;
                            var $option = $('<option>').val(modelId).text(modelName);
                            if (modelId === currentValue) {
                                matched = true;
                                $option.prop('selected', true);
                            }
                            $select.append($option);
                        });

                        // The saved model is not always in the fetched list: it may
                        // have been retired, renamed, or typed in by hand. PHP
                        // handles that when it renders the field, by prepending a
                        // '(saved)' option, and empty() above had just thrown that
                        // option away. Without this the browser falls back to the
                        // first entry, and the next Save silently switches the site
                        // to a model nobody chose and bills for it.
                        if (hasModels && currentValue && !matched) {
                            $select.prepend(
                                $('<option>')
                                    .val(currentValue)
                                    .text(currentValue + ' (saved)')
                                    .prop('selected', true)
                            );
                        }

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
        var present = TRP_LLM_PROVIDERS.some(function(provider) {
            return $('#trp-' + provider + '-api-key').length > 0;
        });

        if (present) {
            TRP_LLM_Models.init();
        }
    });

    var style = document.createElement('style');
    style.textContent = '.trp-llm-refresh-models .dashicons.spin { animation: trp-spin 1s linear infinite; } @keyframes trp-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
    document.head.appendChild(style);

})(jQuery);
