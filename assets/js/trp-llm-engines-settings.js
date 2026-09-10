var TRP_LLM_PROVIDERS = ['openai', 'anthropic', 'openrouter', 'deepseek'];

jQuery(document).on('trpInitFieldToggler', function() {
    TRP_LLM_PROVIDERS.forEach(function(provider) {
        ['api-key', 'model'].forEach(function(field) {
            TRP_Field_Toggler().init('.trp-translation-engine', '#trp-' + provider + '-' + field, provider);
        });
    });
});

(function($) {
    'use strict';

    var config = window.trp_llm_engines || {};
    var i18n = $.extend({
        loading: 'Loading models...',
        error: 'Error loading models. Your selection is unchanged.',
        enter_api_key: 'Enter API key first',
        refresh: 'Refresh Models',
        saved: '(saved)'
    }, config.i18n || {});
    var states = Object.create(null);

    function fields(provider) {
        return {
            select: $('#trp-' + provider + '-model'),
            key: $('#trp-' + provider + '-api-key'),
            button: $('.trp-llm-refresh-models[data-provider="' + provider + '"]'),
            status: $('#trp-' + provider + '-model-status')
        };
    }

    function busy(provider, value) {
        var ui = fields(provider);
        // Never enable a select here: TranslatePress owns the toggler's disabled state.
        // Keep real options visible and submittable while an AJAX request is running.
        ui.select.attr('aria-busy', value ? 'true' : 'false');
        ui.button.prop('disabled', value).find('.dashicons').toggleClass('spin', value);
    }

    function invalidate(provider) {
        var state = states[provider];
        state.sequence++;
        var old = state.xhr;
        state.xhr = null;
        // Invalidate BEFORE abort(): jQuery can call error/complete synchronously.
        if (old) {
            old.abort();
        }
        busy(provider, false);
    }

    function fetchModels(provider, forceRefresh) {
        var state = states[provider];
        var ui = fields(provider);
        var key = ui.key.val();
        invalidate(provider);
        if (provider !== 'openrouter' && !key) {
            ui.status.text(i18n.enter_api_key);
            return;
        }
        var sequence = state.sequence;
        // The selected value never comes from a temporary loading/error option.
        state.value = ui.select.val() || state.value;
        busy(provider, true);
        ui.status.text(i18n.loading);
        function current() {
            return state.sequence === sequence && ui.key.val() === key;
        }
        state.xhr = $.ajax({
            url: config.ajax_url || window.ajaxurl,
            type: 'POST',
            timeout: 35000,
            data: {
                action: 'trp_llm_fetch_models',
                nonce: config.nonce || '',
                provider: provider,
                api_key: key,
                force_refresh: forceRefresh ? 1 : 0
            },
            success: function(response) {
                if (!current()) {
                    return;
                }
                var models = response && response.success && response.data && response.data.models;
                var ids = models && typeof models === 'object' && !Array.isArray(models) ? Object.keys(models) : [];
                // Empty and malformed catalogues must leave ALL existing choices untouched.
                if (!ids.length || ids.some(function(id) { return !id || typeof models[id] !== 'string'; })) {
                    ui.status.text(i18n.error);
                    return;
                }
                var selected = ui.select.val() || state.value;
                var options = [];
                ids.forEach(function(id) {
                    options.push($('<option>').val(id).text(models[id])[0]);
                });
                if (selected && ids.indexOf(selected) === -1) {
                    options.unshift($('<option>').val(selected).text(selected + ' ' + i18n.saved)[0]);
                }
                ui.select.empty().append(options);
                if (selected) {
                    ui.select.val(selected);
                }
                state.value = ui.select.val();
                ui.status.text('');
            },
            error: function(xhr, reason) {
                if (current() && reason !== 'abort') {
                    ui.status.text(i18n.error);
                }
            },
            complete: function() {
                if (current()) {
                    state.xhr = null;
                    busy(provider, false);
                }
            }
        });
    }

    $(function() {
        TRP_LLM_PROVIDERS.forEach(function(provider) {
            var ui = fields(provider);
            if (!ui.select.length) {
                return;
            }
            states[provider] = { sequence: 0, xhr: null, value: ui.select.val() };
            if (!ui.button.length) {
                $('<button>', {
                    type: 'button',
                    'class': 'button trp-llm-refresh-models',
                    'data-provider': provider
                }).append($('<span>', { 'class': 'dashicons dashicons-update', 'aria-hidden': 'true' }))
                    .append(document.createTextNode(' ' + i18n.refresh)).insertAfter(ui.select);
            }
            $('<span>', {
                id: 'trp-' + provider + '-model-status',
                'class': 'trp-llm-model-status',
                role: 'status',
                'aria-live': 'polite'
            }).insertAfter(fields(provider).button);
            ui.select.on('change.trpLlm', function() {
                states[provider].value = $(this).val();
            });
            ui.key.on('input.trpLlm', function() {
                invalidate(provider);
                fields(provider).status.text('');
            }).on('blur.trpLlm', function() {
                fetchModels(provider, false);
            });
            fields(provider).button.on('click.trpLlm', function(event) {
                event.preventDefault();
                fetchModels(provider, true);
            });
        });
    });

    var style = document.createElement('style');
    style.textContent = '.trp-llm-refresh-models{margin-left:10px}.trp-llm-model-status{display:block}.trp-llm-refresh-models .dashicons.spin{animation:trp-spin 1s linear infinite}@keyframes trp-spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}';
    document.head.appendChild(style);
})(jQuery);
