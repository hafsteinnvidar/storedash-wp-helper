/**
 * Storedash Unified Admin Interface JavaScript
 */

(function($) {
    'use strict';

    const StoredashWpAdmin = {

        /**
         * Initialize
         */
        init: function() {
            this.initTabs();
            this.initCartSettingsActions();
            this.initDiagnosticsActions();
        },

        /**
         * Initialize tab navigation with accessibility support
         */
        initTabs: function() {
            $('.storedash-wp-admin-nav__link').on('click', function(e) {
                e.preventDefault();
                const tab = $(this).data('tab');

                // Update nav links and ARIA attributes
                $('.storedash-wp-admin-nav__link')
                    .removeClass('storedash-wp-admin-nav__link--active')
                    .attr('aria-selected', 'false');
                
                $(this)
                    .addClass('storedash-wp-admin-nav__link--active')
                    .attr('aria-selected', 'true');

                // Update panels
                $('.storedash-wp-tabs__panel').removeClass('storedash-wp-tabs__panel--active');
                $(`.storedash-wp-tabs__panel[data-panel="${tab}"]`).addClass('storedash-wp-tabs__panel--active');

                // Update URL hash
                window.location.hash = tab;

                // Save active tab to localStorage
                localStorage.setItem('storedashWpActiveTab', tab);
            });

            // Keyboard navigation for tabs
            $('.storedash-wp-admin-nav__link').on('keydown', function(e) {
                const $tabs = $('.storedash-wp-admin-nav__link');
                const currentIndex = $tabs.index(this);
                let nextIndex;

                // Arrow key navigation
                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    nextIndex = (currentIndex + 1) % $tabs.length;
                    $tabs.eq(nextIndex).focus().click();
                } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    nextIndex = (currentIndex - 1 + $tabs.length) % $tabs.length;
                    $tabs.eq(nextIndex).focus().click();
                } else if (e.key === 'Home') {
                    e.preventDefault();
                    $tabs.first().focus().click();
                } else if (e.key === 'End') {
                    e.preventDefault();
                    $tabs.last().focus().click();
                }
            });

            // Restore last active tab from URL hash or localStorage
            let activeTab = window.location.hash.substring(1);
            if (!activeTab) {
                activeTab = localStorage.getItem('storedashWpActiveTab');
            }
            if (activeTab) {
                $(`.storedash-wp-admin-nav__link[data-tab="${activeTab}"]`).click();
            }
        },

        /**
         * Initialize Cart Settings Actions
         */
        initCartSettingsActions: function() {
            // Save cart settings
            $('#storedash-wp-save-cart-settings').on('click', function() {
                const $btn = $(this);
                const originalText = $btn.html();

                $btn.prop('disabled', true).html('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite;"><circle cx="12" cy="12" r="10"></circle></svg> Saving...');

                $.ajax({
                    url: storedashWpAdmin.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'storedash_wp_save_cart_settings',
                        nonce: storedashWpAdmin.nonce,
                        clean_uninstall: $('#clean_uninstall').is(':checked') ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            StoredashWpAdmin.showMessage('#storedash-wp-cart-message', response.data.message, 'success');
                        } else {
                            StoredashWpAdmin.showMessage('#storedash-wp-cart-message', response.data.message, 'error');
                        }
                        $btn.prop('disabled', false).html(originalText);
                    },
                    error: function() {
                        StoredashWpAdmin.showMessage('#storedash-wp-cart-message', 'Error occurred. Please try again.', 'error');
                        $btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        },

        /**
         * Initialize Diagnostics Actions
         */
        initDiagnosticsActions: function() {
            // Run diagnostics test
            $('#storedash-wp-run-diagnostics').on('click', function() {
                const $btn = $(this);
                const $loading = $('#storedash-wp-test-loading');
                const $results = $('#storedash-wp-test-results');
                const $output = $('#storedash-wp-test-output');

                $btn.prop('disabled', true);
                $loading.show();
                $results.hide();
                $output.html('');

                $.ajax({
                    url: storedashWpAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'storedash_wp_run_diagnostics',
                        nonce: storedashWpAdmin.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $output.html(response.data.html);
                        } else {
                            $output.html('<div class="test-error">Error: ' + response.data.message + '</div>');
                        }
                        $results.show();
                    },
                    error: function(xhr, status, error) {
                        $output.html('<div class="test-error">AJAX Error: ' + error + '</div>');
                        $results.show();
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                        $loading.hide();
                    }
                });
            });

            // Copy system info to clipboard
            $('#storedash-wp-copy-system-info').on('click', function() {
                const $btn = $(this);
                const originalText = $btn.html();

                $btn.prop('disabled', true).html('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite;"><circle cx="12" cy="12" r="10"></circle></svg> Copying...');

                $.ajax({
                    url: storedashWpAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'storedash_wp_copy_system_info',
                        nonce: storedashWpAdmin.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // Copy to clipboard
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(response.data.text).then(function() {
                                    StoredashWpAdmin.showMessage('#storedash-wp-admin-message', 'System info copied to clipboard!', 'success');
                                    $btn.prop('disabled', false).html(originalText);
                                }).catch(function() {
                                    // Fallback: create temporary textarea
                                    const textarea = document.createElement('textarea');
                                    textarea.value = response.data.text;
                                    textarea.style.position = 'fixed';
                                    textarea.style.opacity = '0';
                                    document.body.appendChild(textarea);
                                    textarea.select();
                                    try {
                                        document.execCommand('copy');
                                        StoredashWpAdmin.showMessage('#storedash-wp-admin-message', 'System info copied to clipboard!', 'success');
                                    } catch (err) {
                                        StoredashWpAdmin.showMessage('#storedash-wp-admin-message', 'Failed to copy. Please use Download instead.', 'error');
                                    }
                                    document.body.removeChild(textarea);
                                    $btn.prop('disabled', false).html(originalText);
                                });
                            } else {
                                // Fallback for older browsers
                                const textarea = document.createElement('textarea');
                                textarea.value = response.data.text;
                                textarea.style.position = 'fixed';
                                textarea.style.opacity = '0';
                                document.body.appendChild(textarea);
                                textarea.select();
                                try {
                                    document.execCommand('copy');
                                    StoredashWpAdmin.showMessage('#storedash-wp-admin-message', 'System info copied to clipboard!', 'success');
                                } catch (err) {
                                    StoredashWpAdmin.showMessage('#storedash-wp-admin-message', 'Failed to copy. Please use Download instead.', 'error');
                                }
                                document.body.removeChild(textarea);
                                $btn.prop('disabled', false).html(originalText);
                            }
                        } else {
                            StoredashWpAdmin.showMessage('#storedash-wp-admin-message', response.data.message || 'Error occurred', 'error');
                            $btn.prop('disabled', false).html(originalText);
                        }
                    },
                    error: function() {
                        StoredashWpAdmin.showMessage('#storedash-wp-admin-message', 'Error occurred. Please try again.', 'error');
                        $btn.prop('disabled', false).html(originalText);
                    }
                });
            });

            // Download system info as text file
            $('#storedash-wp-download-system-info').on('click', function() {
                const $btn = $(this);
                const originalText = $btn.html();

                $btn.prop('disabled', true).html('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite;"><circle cx="12" cy="12" r="10"></circle></svg> Preparing...');

                // Create form and submit to trigger download
                const form = $('<form>', {
                    method: 'POST',
                    action: storedashWpAdmin.ajaxUrl
                });
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'action',
                    value: 'storedash_wp_download_system_info'
                }));
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'nonce',
                    value: storedashWpAdmin.nonce
                }));
                $('body').append(form);
                form.submit();
                form.remove();

                setTimeout(function() {
                    $btn.prop('disabled', false).html(originalText);
                    StoredashWpAdmin.showMessage('#storedash-wp-admin-message', 'Download started!', 'success');
                }, 500);
            });

            // Export system info as JSON file
            $('#storedash-wp-export-system-info').on('click', function() {
                const $btn = $(this);
                const originalText = $btn.html();

                $btn.prop('disabled', true).html('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite;"><circle cx="12" cy="12" r="10"></circle></svg> Preparing...');

                // Create form and submit to trigger download
                const form = $('<form>', {
                    method: 'POST',
                    action: storedashWpAdmin.ajaxUrl
                });
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'action',
                    value: 'storedash_wp_export_system_info'
                }));
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'nonce',
                    value: storedashWpAdmin.nonce
                }));
                $('body').append(form);
                form.submit();
                form.remove();

                setTimeout(function() {
                    $btn.prop('disabled', false).html(originalText);
                    StoredashWpAdmin.showMessage('#storedash-wp-admin-message', 'Export started!', 'success');
                }, 500);
            });

            // Collapsible sections — default to collapsed
            $('.storedash-wp-section-toggle').each(function() {
                const $toggle = $(this);
                const $section = $toggle.closest('.storedash-wp-diagnostics-section');
                const $content = $section.find('.storedash-wp-section-content');
                if ($toggle.attr('aria-expanded') === 'false') {
                    $content.hide();
                    $section.addClass('is-collapsed');
                    $toggle.find('svg').css('transform', 'rotate(-90deg)');
                }
            });

            // Toggle when clicking anywhere on the section header (not just the chevron).
            $('.storedash-wp-diagnostics-section .storedash-wp-card__header').on('click', function() {
                const $section = $(this).closest('.storedash-wp-diagnostics-section');
                const $toggle = $section.find('.storedash-wp-section-toggle');
                const $content = $section.find('.storedash-wp-section-content');
                const isExpanded = $toggle.attr('aria-expanded') === 'true';

                if (isExpanded) {
                    $content.slideUp(200);
                    $section.addClass('is-collapsed');
                    $toggle.attr('aria-expanded', 'false');
                    $toggle.find('svg').css('transform', 'rotate(-90deg)');
                } else {
                    $content.slideDown(200);
                    $section.removeClass('is-collapsed');
                    $toggle.attr('aria-expanded', 'true');
                    $toggle.find('svg').css('transform', 'rotate(0deg)');
                }
            });

            // Test webhook button (diagnostics tab)
            $('#storedash-wp-diag-test-webhook').on('click', function() {
                const $btn = $(this);
                const $result = $('#storedash-wp-diag-test-webhook-result');
                const originalText = $btn.html();
                $btn.prop('disabled', true).html('Sending...');
                $result.html('');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'storedash_wp_test_webhook', nonce: storedashWpAdmin.nonce },
                    success: function(response) {
                        if (response.success) {
                            var d = response.data;
                            var html = '<span class="storedash-wp-text-success">✓ ' + d.message + '</span>';
                            if (d.status_code) html += ' <em>(HTTP ' + d.status_code + ')</em>';
                            $result.html(html);
                        } else {
                            var msg = response.data ? response.data.message : 'Unknown error';
                            $result.html('<span class="storedash-wp-text-warning">✗ ' + msg + '</span>');
                        }
                    },
                    error: function(xhr, status, error) {
                        $result.html('<span class="storedash-wp-text-warning">✗ AJAX Error: ' + error + '</span>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html(originalText);
                    }
                });
            });

            // Check REST API endpoints health
            $('#storedash-wp-check-endpoints').on('click', function() {
                const $btn = $(this);
                const $resultsContainer = $('#storedash-wp-endpoints-results');
                const originalText = $btn.html();

                $btn.prop('disabled', true).html('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite;"><circle cx="12" cy="12" r="10"></circle></svg> Checking...');

                // Show loading state
                $resultsContainer.prepend('<div class="storedash-wp-endpoints-loading"><div class="spinner"></div> Checking endpoints health...</div>');

                $.ajax({
                    url: storedashWpAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'storedash_wp_check_endpoints',
                        nonce: storedashWpAdmin.nonce
                    },
                    success: function(response) {
                        // Remove loading state
                        $resultsContainer.find('.storedash-wp-endpoints-loading').remove();

                        if (response.success) {
                            // Update the results table
                            $resultsContainer.html(response.data.html);

                            // Show summary message
                            const summary = response.data.summary;
                            let messageType = 'success';
                            let message = 'All ' + summary.healthy + ' endpoints are healthy!';

                            if (summary.unhealthy > 0) {
                                messageType = 'warning';
                                message = summary.healthy + ' of ' + summary.total + ' endpoints healthy. ' + summary.unhealthy + ' issue(s) found.';
                            }

                            StoredashWpAdmin.showSnackbar(message, messageType);
                        } else {
                            StoredashWpAdmin.showSnackbar('Error checking endpoints: ' + (response.data.message || 'Unknown error'), 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        // Remove loading state
                        $resultsContainer.find('.storedash-wp-endpoints-loading').remove();
                        StoredashWpAdmin.showSnackbar('AJAX Error: ' + error, 'error');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        },


        /**
         * Show message (legacy - for inline messages)
         */
        showMessage: function(selector, message, type) {
            const $message = $(selector);
            $message.removeClass('success error info').addClass(type);
            $message.html(message).fadeIn();

            setTimeout(() => {
                $message.fadeOut();
            }, 5000);
        },

        /**
         * Show snackbar notification (modern toast notification)
         */
        showSnackbar: function(message, type = 'success') {
            // Create snackbar container if it doesn't exist
            if (!$('.storedash-wp-snackbar-container').length) {
                $('body').append('<div class="storedash-wp-snackbar-container"></div>');
            }

            const icons = {
                success: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M16.667 5L7.5 14.167l-4.167-4.167"/></svg>',
                error: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10" cy="10" r="8.333"/><path d="M10 6.667V10M10 13.333h.008"/></svg>',
                warning: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 6.667v4M10 14h.01M18 10a8 8 0 11-16 0 8 8 0 0116 0z"/></svg>',
                info: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10" cy="10" r="8.333"/><path d="M10 13.333V10M10 6.667h.008"/></svg>'
            };

            const $snackbar = $(`
                <div class="storedash-wp-snackbar storedash-wp-snackbar--${type}">
                    <div class="storedash-wp-snackbar__icon">${icons[type] || icons.info}</div>
                    <div class="storedash-wp-snackbar__message">${message}</div>
                    <button type="button" class="storedash-wp-snackbar__close" aria-label="Close">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="4" x2="4" y2="12"></line>
                            <line x1="4" y1="4" x2="12" y2="12"></line>
                        </svg>
                    </button>
                </div>
            `);

            $('.storedash-wp-snackbar-container').append($snackbar);

            // Auto dismiss after 5 seconds
            const timeout = setTimeout(() => {
                $snackbar.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);

            // Manual dismiss
            $snackbar.find('.storedash-wp-snackbar__close').on('click', function() {
                clearTimeout(timeout);
                $snackbar.fadeOut(300, function() {
                    $(this).remove();
                });
            });
        },
    };

    // Initialize when document is ready
    $(document).ready(function() {
        StoredashWpAdmin.init();
    });

    // Add spin animation for loading icons
    $('<style>@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>').appendTo('head');

})(jQuery);
