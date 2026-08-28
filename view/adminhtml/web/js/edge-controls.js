/**
 * Cache Management edge-cache controls.
 *
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    $.widget('commerce.commerceEdgeControls', {
        options: {
            flushUrl: '',
            healthUrl: '',
        },

        /** @private */
        _create: function () {
            this.$url = this.element.find('[data-edge="url"]');
            this.$result = this.element.find('[data-edge="result"]');

            this._on(this.element.find('[data-edge="flush"]'), { click: '_flush' });
            this._on(this.element.find('[data-edge="health"]'), { click: '_health' });
        },

        /** @private */
        _flush: function (event) {
            event.preventDefault();

            // Purging is destructive and, misconfigured, can hit production.
            if (!window.confirm($t('Purge this URL from the edge cache?'))) {
                return;
            }

            // POST, with the form key, because this changes state.
            this._request(this.options.flushUrl, 'POST', {
                url: this.$url.val(),
                form_key: window.FORM_KEY,
            });
        },

        /** @private */
        _health: function (event) {
            event.preventDefault();
            this._request(this.options.healthUrl, 'GET', { url: this.$url.val() });
        },

        /** @private */
        _request: function (url, method, data) {
            var self = this;

            if (!data.url) {
                this._show($t('Enter a URL first.'), false);

                return;
            }

            this._show($t('Working…'), true);

            $.ajax({ url: url, type: method, data: data, dataType: 'json' })
                .done(function (response) {
                    self._show(response.message, response.success);
                })
                .fail(function (xhr) {
                    var message = $t('The request failed.');

                    try {
                        message = JSON.parse(xhr.responseText).message || message;
                    } catch (e) {
                        // Keep the generic message.
                    }

                    self._show(message, false);
                });
        },

        /** @private */
        _show: function (message, success) {
            this.$result
                .removeClass('message-success message-error')
                .addClass(success ? 'message-success' : 'message-error')
                .text(message)
                .show();
        },
    });

    return $.commerce.commerceEdgeControls;
});
