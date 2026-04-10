/**
 * MigrateGuru Onboarding - WordPress Plugin Integration
 * Handles the 3-step migration process with WordPress AJAX and Rails API integration
 */

(function($) {
    'use strict';

    // ==================== STATE MANAGEMENT ====================

    const mgState = {
        destinationUrl: '',
        validatedSteps: {},
        step2ValidatedKey: '',
        step2KeyEdited: false
    };

    // ==================== UTILITY FUNCTIONS ====================

    /**
     * Show toast notification
     */
    function showToast(message, type = 'success') {
        const container = $('#mg-toast-container');
        
        const iconSvg = type === 'success' 
            ? '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>'
            : '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>';
        
        const toast = $('<div>').addClass('mg-toast ' + type).html(`
            <svg class="mg-toast-icon ${type}" viewBox="0 0 20 20" fill="currentColor">
                ${iconSvg}
            </svg>
            <span class="mg-toast-message">${message}</span>
        `);
        
        container.append(toast);
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            toast.addClass('removing');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    /**
     * Open modal
     */
    function openModal(modalId) {
        $('#' + modalId).addClass('mg-active');
        $('body').css('overflow', 'hidden');
    }

    /**
     * Close modal
     */
    function closeModal(modalId) {
        $('#' + modalId).removeClass('mg-active');
        $('body').css('overflow', '');
    }

    /**
     * Copy text to clipboard
     */
    async function copyToClipboard(text) {
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
                return true;
            } else {
                // Fallback method
                const textArea = $('<textarea>')
                    .val(text)
                    .css({
                        position: 'fixed',
                        left: '-999999px'
                    })
                    .appendTo('body');
                
                textArea[0].select();
                const successful = document.execCommand('copy');
                textArea.remove();
                return successful;
            }
        } catch (err) {
            return false;
        }
    }

    /**
     * Validate email format
     * More comprehensive regex that checks for:
     * - Valid local part (before @)
     * - Valid domain part (after @)
     * - At least one dot in domain
     * - No spaces
     * Note: HTML5 type="email" provides browser-level validation,
     * and backend uses WordPress's is_email() function for final validation
     */
    function isValidEmail(email) {
        // More comprehensive email regex pattern
        // Allows most valid email formats while rejecting obviously invalid ones
        const emailRegex = /^[a-zA-Z0-9.!#$%&'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;
        return emailRegex.test(email) && email.length <= 254; // RFC 5321 max length
    }

    /**
     * Decode base64 string safely
     */
    function decodeBase64(value) {
        try {
            return atob(value);
        } catch (error) {
            return null;
        }
    }

    /**
     * Escape text for safe HTML interpolation
     */
    function escapeHtml(value = '') {
        return $('<div>').text(value || '').html();
    }

    /**
     * Format URL for compact display
     */
    function formatUrlForDisplay(url = '') {
        if (!url) {
            return 'Unknown';
        }
        return url.replace(/^https?:\/\//i, '').replace(/\/$/, '');
    }

    /**
     * Update Step 3 migration overview details
     */
    function updateStep3Overview() {
        const sourceUrl = mgOnboarding.currentSiteUrl || '';
        const formattedSource = sourceUrl ? formatUrlForDisplay(sourceUrl) : '—';
        const $sourceCard = $('#mg-step3-source-card');
        const $sourceLink = $('#mg-step3-source-url');

        if (sourceUrl) {
            $sourceCard.attr('title', sourceUrl);
            $sourceLink
                .removeClass('mg-site-url-disabled')
                .attr('href', sourceUrl)
                .text(formattedSource);
        } else {
            $sourceCard.removeAttr('title');
            $sourceLink
                .addClass('mg-site-url-disabled')
                .attr('href', '#')
                .text('—');
        }

        const $destinationCard = $('#mg-step3-destination-card');
        const $destinationTitle = $('#mg-step3-destination-title');
        const $destinationLink = $('#mg-step3-destination-url');
        
        // Get destination URL from state or data attribute (for persistence)
        const $keyInput = $('#mg-key-input');
        const destinationUrl = mgState.destinationUrl || $keyInput.data('destination-url') || '';

        if (destinationUrl) {
            // Ensure state is synced with data attribute
            if (!mgState.destinationUrl) {
                mgState.destinationUrl = destinationUrl;
            }
            const formattedDest = formatUrlForDisplay(destinationUrl);
            $destinationCard.removeClass('mg-site-card-empty').attr('title', destinationUrl);
            $destinationTitle.text('New Site');
            $destinationLink
                .removeClass('mg-site-url-disabled')
                .attr('href', destinationUrl)
                .text(formattedDest);
        } else {
            $destinationCard.addClass('mg-site-card-empty').removeAttr('title');
            $destinationTitle.text('New Site');
            $destinationLink
                .addClass('mg-site-url-disabled')
                .attr('href', '#')
                .text('Validate the key in Step 2 to see the destination details.');
        }
    }

    /**
     * Validate destination migration key (mirrors bv_parse_api_key)
     */
    function validateApiKey(key) {
        const invalidResponse = (message) => ({
            valid: false,
            message: message || 'Invalid migration key. Please copy it again from your destination site.'
        });

        const decoded = decodeBase64(key);
        if (!decoded) {
            return invalidResponse('Migration key is not properly encoded.');
        }

        const parts = decoded.split(':');
        if (parts.length < 2) {
            return invalidResponse('Migration key appears to be incomplete.');
        }

        const version = parts.shift();
        if (version !== 'v2') {
            return invalidResponse('This migration key is from an older plugin version. Install the latest MigrateGuru plugin on both sites and copy the key again.');
        }

        const payload = parts.join(':');
        let secret;
        let url;
        let plugname = '';

        const decodeUrlOrThrow = (value) => {
            const decodedUrl = decodeBase64(value);
            if (!decodedUrl) {
                throw new Error('Migration key URL is invalid.');
            }
            return decodedUrl;
        };

        try {
            const inner = payload.split(':', 3);
            if (inner.length < 2) {
                return invalidResponse('Migration key appears to be incomplete.');
            }
            secret = inner[0];
            url = decodeUrlOrThrow(inner[1]);
            plugname = inner[2] || '';
        } catch (error) {
            return invalidResponse(error.message);
        }

        if (!secret || secret.length < 32) {
            return invalidResponse('Migration key secret is invalid.');
        }

        if (!url) {
            return invalidResponse('Migration key URL is invalid.');
        }

        return {
            valid: true,
            data: {
                secret,
                url,
                plugname
            }
        };
    }

    /**
     * Update step state
     */
    function updateStepState(stepNumber, state) {
        const stepCard = $('#mg-step' + stepNumber);
        const badge = $('#mg-step' + stepNumber + '-badge');
        const status = $('#mg-step' + stepNumber + '-status');
        
        // Remove all state classes
        stepCard.removeClass('mg-active mg-disabled mg-completed mg-collapsed');
        
        // Add new state
        switch (state) {
            case 'active':
                stepCard.addClass('mg-active');
                break;
            case 'completed':
                stepCard.addClass('mg-completed mg-collapsed');
                if (badge.length) {
                    badge.addClass('mg-completed').html('✓');
                }
                if (status.length) {
                    status.show();
                }
                break;
            case 'expanded':
                // Re-opened completed step - keep completed badge but expand content
                stepCard.addClass('mg-completed');
                stepCard.removeClass('mg-collapsed');
                if (badge.length && badge.hasClass('mg-completed')) {
                    // Keep the checkmark
                }
                // Show status only if step is validated and (for Step 2) key hasn't been edited
                if (status.length && mgState.validatedSteps[stepNumber]) {
                    if (stepNumber === 2) {
                        // For Step 2, only show status if key hasn't been edited
                        if (!mgState.step2KeyEdited) {
                            status.show();
                        } else {
                            status.hide();
                        }
                    } else {
                        // For other steps, show status if validated
                        status.show();
                    }
                }
                break;
            case 'disabled':
                stepCard.addClass('mg-disabled');
                break;
        }
    }

    /**
     * Disable subsequent steps based on the active step
     */
    function disableSubsequentSteps(activeStepNumber) {
        // Disable steps based on which step is active
        if (activeStepNumber === 1) {
            // If step 1 is active, disable step 2 and step 3
            updateStepState(2, 'disabled');
            updateStepState(3, 'disabled');
        } else if (activeStepNumber === 2) {
            // If step 2 is active, disable step 3
            updateStepState(3, 'disabled');
        }
        // If step 3 is active, no steps need to be disabled
    }

    /**
     * Enable step
     */
    function enableStep(stepNumber) {
        updateStepState(stepNumber, 'active');
        
        // Disable subsequent steps when enabling a step
        disableSubsequentSteps(stepNumber);
        
        // Smooth scroll to the step
        // setTimeout(() => {
        //     $('html, body').animate({
        //         scrollTop: $('#mg-step' + stepNumber).offset().top - 100
        //     }, 500);
        // }, 300);
    }

    /**
     * Expand a collapsed step
     */
    function expandStep(stepNumber) {
        const stepCard = $('#mg-step' + stepNumber);
        
        // Only expand if step is collapsed
        if (!stepCard.hasClass('mg-collapsed')) {
            return;
        }
        
        // Update state to expanded
        updateStepState(stepNumber, 'expanded');
        
        // Disable subsequent steps when expanding a step
        disableSubsequentSteps(stepNumber);
        
        // Step 2 specific: Pre-fill validated key if available and not edited
        if (stepNumber === 2) {
            const $keyInput = $('#mg-key-input');
            const $step2Status = $('#mg-step2-status');
            
            if (mgState.step2ValidatedKey && !mgState.step2KeyEdited) {
                // Pre-fill the validated key
                $keyInput.val(mgState.step2ValidatedKey);
                
                // Show validation status
                if ($step2Status.length && mgState.validatedSteps[2]) {
                    $step2Status.show();
                }
                
                // Restore destination URL if available
                if (mgState.destinationUrl) {
                    updateStep3Overview();
                }
            } else if (mgState.step2KeyEdited) {
                // Key was edited, hide validation status
                if ($step2Status.length) {
                    $step2Status.hide();
                }
            }
        }
        
        // Smooth scroll to the step
        // setTimeout(() => {
        //     $('html, body').animate({
        //         scrollTop: stepCard.offset().top - 100
        //     }, 500);
        // }, 100);
    }

    /**
     * Get site info data from hidden form
     */
    function getSiteInfoData() {
        const data = {};
        $('#mg-site-info-form input[type="hidden"]').each(function() {
            data[$(this).attr('name')] = $(this).val();
        });
        return data;
    }

    // ==================== MODAL: COPY KEY ====================

    function setupCopyKeyModal() {
        const $copyKeyBtn = $('#mg-copy-key-btn');
        const $copyKeyModal = $('#mg-copy-key-modal');
        const $modalKeyInput = $('#mg-modal-key-input');
        const $toggleVisibilityBtn = $('#mg-toggle-key-visibility');
        const $eyeIcon = $('#mg-eye-icon');
        const $eyeOffIcon = $('#mg-eye-off-icon');
        const $copyKeyFromModalBtn = $('#mg-copy-key-from-modal');
        const $closeBtn = $copyKeyModal.find('.mg-modal-close');
        const $overlay = $copyKeyModal.find('.mg-modal-overlay');
        
        // Open modal
        $copyKeyBtn.on('click', () => {
            openModal('mg-copy-key-modal');
        });
        
        // Toggle key visibility
        let isKeyVisible = false;
        $toggleVisibilityBtn.on('click', () => {
            isKeyVisible = !isKeyVisible;
            $modalKeyInput.attr('type', isKeyVisible ? 'text' : 'password');
            $eyeIcon.css('display', isKeyVisible ? 'none' : 'block');
            $eyeOffIcon.css('display', isKeyVisible ? 'block' : 'none');
        });
        
        // Copy key from modal
        $copyKeyFromModalBtn.on('click', async () => {
            const success = await copyToClipboard(mgOnboarding.currentSiteKey);
            if (success) {
                showToast('Migration key copied to clipboard!', 'success');
                closeModal('mg-copy-key-modal');
            } else {
                showToast('Failed to copy key. Please try again.', 'error');
            }
        });
        
        // Close modal
        $closeBtn.on('click', () => closeModal('mg-copy-key-modal'));
        $overlay.on('click', () => closeModal('mg-copy-key-modal'));
    }

    // ==================== MODAL: GET KEY ====================

    function setupGetKeyModal() {
        const $getKeyBtn = $('#mg-get-key-btn');
        const $getKeyModal = $('#mg-get-key-modal');
        const $gotItBtn = $('#mg-got-it-btn');
        const $closeBtn = $getKeyModal.find('.mg-modal-close');
        const $overlay = $getKeyModal.find('.mg-modal-overlay');
        const $videoIframe = $('#mg-get-key-video-iframe');
        
        // Open modal
        $getKeyBtn.on('click', () => {
            openModal('mg-get-key-modal');
            // Load/reload the video to start from beginning
            const videoSrc = $videoIframe.data('src');
            if (videoSrc) {
                $videoIframe.attr('src', videoSrc);
            }
        });
        
        // Close modal and stop video
        const closeModalAndStopVideo = () => {
            closeModal('mg-get-key-modal');
            // Clear video src to stop playback
            $videoIframe.attr('src', '');
        };
        
        $gotItBtn.on('click', closeModalAndStopVideo);
        $closeBtn.on('click', closeModalAndStopVideo);
        $overlay.on('click', closeModalAndStopVideo);
    }


    // ==================== STEP 1: INSTALL PLUGIN ====================

    function setupStep1() {
        const $step1ContinueBtn = $('#mg-step1-continue-btn');
        
        $step1ContinueBtn.on('click', () => {
            // Mark Step 1 as completed and validated
            mgState.validatedSteps[1] = true;
            updateStepState(1, 'completed');
            // Enable Step 2
            enableStep(2);
        });
    }

    // ==================== STEP 2: VALIDATE KEY ====================

    function setupStep2() {
        const $keyInput = $('#mg-key-input');
        const $validateKeyBtn = $('#mg-validate-key-btn');
        const $keyError = $('#mg-key-error');
        const $toggleStep2KeyBtn = $('#mg-toggle-step2-key-visibility');
        const $eyeIconStep2 = $('.mg-eye-icon-step2');
        const $eyeOffIconStep2 = $('.mg-eye-off-icon-step2');
        
        // Toggle key visibility for Step 2
        let isStep2KeyVisible = false;
        $toggleStep2KeyBtn.on('click', () => {
            isStep2KeyVisible = !isStep2KeyVisible;
            $keyInput.attr('type', isStep2KeyVisible ? 'text' : 'password');
            $eyeIconStep2.css('display', isStep2KeyVisible ? 'none' : 'block');
            $eyeOffIconStep2.css('display', isStep2KeyVisible ? 'block' : 'none');
        });
        
        // Clear error on input and reset destination URL
        $keyInput.on('input', () => {
            const currentKey = $keyInput.val().trim();
            $keyError.hide().text('');
            $keyInput.removeClass('mg-error');
            
            // Check if key has been edited from validated key
            if (mgState.step2ValidatedKey) {
                if (currentKey !== mgState.step2ValidatedKey) {
                    // Key differs from validated key
                    mgState.step2KeyEdited = true;
                    // Clear validation status
                    const $step2Status = $('#mg-step2-status');
                    if ($step2Status.length) {
                        $step2Status.hide();
                    }
                    // Clear validated step flag
                    mgState.validatedSteps[2] = false;
                    // Clear destination URL
                    mgState.destinationUrl = '';
                    $keyInput.removeData('destination-url');
                    updateStep3Overview();
                } else if (currentKey === mgState.step2ValidatedKey && mgState.step2KeyEdited) {
                    // Key matches validated key again - restore validation status
                    mgState.step2KeyEdited = false;
                    mgState.validatedSteps[2] = true;
                    // Restore destination URL if available
                    const storedUrl = $keyInput.data('destination-url');
                    if (storedUrl) {
                        mgState.destinationUrl = storedUrl;
                        updateStep3Overview();
                    }
                    // Show validation status if step is expanded
                    const $step2Status = $('#mg-step2-status');
                    if ($step2Status.length && !$('#mg-step2').hasClass('mg-collapsed')) {
                        $step2Status.show();
                    }
                }
            } else {
                // No validated key yet, just clear destination URL
                mgState.destinationUrl = '';
                $keyInput.removeData('destination-url');
                updateStep3Overview();
            }
        });
        
        // Validate key
        $validateKeyBtn.on('click', () => {
            const enteredKey = $keyInput.val().trim();
            
            // Validate empty
            if (!enteredKey) {
                $keyError.text('Please enter a migration key').show();
                $keyInput.addClass('mg-error');
                return;
            }
            
            // Clear any errors
            $keyError.hide();
            $keyInput.removeClass('mg-error');

            // Check if destination key matches current site key
            if (enteredKey === mgOnboarding.currentSiteKey) {
                $keyError.text('You cannot use the same site key. Please enter the migration key from your destination site (the site you are migrating to).').show();
                $keyInput.addClass('mg-error');
                return;
            }

            // Validate format (client side)
            const validationResult = validateApiKey(enteredKey);
            if (!validationResult.valid) {
                $keyError.text(validationResult.message).show();
                $keyInput.addClass('mg-error');
                return;
            }
            
            // Disable button during validation
            $validateKeyBtn.prop('disabled', true);
            
            // Make AJAX call to validate keys
            $.ajax({
                url: mgOnboarding.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mg_validate_key',
                    nonce: mgOnboarding.nonce,
                    destination_key: enteredKey,
                    source_key: mgOnboarding.currentSiteKey
                },
                success: function(response) {
                    if (response.success) {
                        // Store validated key and mark as validated
                        mgState.step2ValidatedKey = enteredKey;
                        mgState.step2KeyEdited = false;
                        mgState.validatedSteps[2] = true;
                        
                        // Store destination URL in state and as data attribute for persistence
                        mgState.destinationUrl = validationResult.data.url;
                        $keyInput.data('destination-url', validationResult.data.url);
                        updateStep3Overview();
                        
                        // Mark Step 2 as completed
                        updateStepState(2, 'completed');
                        
                        // Enable Step 3
                        enableStep(3);
                    } else {
                        $keyError.text(response.data.message || 'Validation failed. Please try again.').show();
                        $keyInput.addClass('mg-error');
                    }
                },
                error: function(xhr, status, error) {
                    $keyError.text('An error occurred during validation. Please try again.').show();
                    $keyInput.addClass('mg-error');
                },
                complete: function() {
                    $validateKeyBtn.prop('disabled', false);
                }
            });
        });
        
        // Allow Enter key to validate
        $keyInput.on('keypress', (e) => {
            if (e.which === 13) {
                $validateKeyBtn.click();
            }
        });
    }

    // ==================== STEP 3: INITIATE MIGRATION ====================

    function setupStep3() {
        const $emailInput = $('#mg-email-input');
        const $initiateMigrationBtn = $('#mg-initiate-migration-btn');
        const $emailError = $('#mg-email-error');
        
        // Clear error on input - preserve destination URL state
        $emailInput.on('input', () => {
            $emailError.hide().text('');
            $emailInput.removeClass('mg-error');
            // Ensure destination URL is preserved from data attribute if state is lost
            const $keyInput = $('#mg-key-input');
            const storedDestinationUrl = $keyInput.data('destination-url');
            if (storedDestinationUrl && !mgState.destinationUrl) {
                mgState.destinationUrl = storedDestinationUrl;
            }
            // Update overview to ensure destination details are displayed
            updateStep3Overview();
        });
        
        // Initiate migration
        $initiateMigrationBtn.on('click', () => {
            const email = $emailInput.val().trim();
            const destinationKey = $('#mg-key-input').val().trim();
            
            // Validate empty
            if (!email) {
                $emailError.text('Please enter your email address').show();
                $emailInput.addClass('mg-error');
                return;
            }
            
            // Validate format
            if (!isValidEmail(email)) {
                $emailError.text('Please enter a valid email address').show();
                $emailInput.addClass('mg-error');
                return;
            }

            // Ensure destination key is still valid
            const destinationKeyValidation = validateApiKey(destinationKey);
            if (!destinationKeyValidation.valid) {
                showToast(destinationKeyValidation.message, 'error');
                return;
            }
            
            // Clear any errors
            $emailError.hide();
            $emailInput.removeClass('mg-error');
            
            // Disable button and show loading state
            $initiateMigrationBtn.prop('disabled', true).html(`
                <svg class="mg-spinner mg-btn-icon" width="16" height="16" viewBox="0 0 16 16">
                    <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5" fill="none" stroke-dasharray="40" stroke-dashoffset="0"/>
                </svg>
                Starting Migration...
            `);
            
            // Get site info data
            const siteInfoData = getSiteInfoData();
            
            // Make AJAX call to initiate migration
            $.ajax({
                url: mgOnboarding.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mg_initiate_migration',
                    nonce: mgOnboarding.nonce,
                    email: email,
                    destination_key: destinationKey,
                    source_key: mgOnboarding.currentSiteKey,
                    site_info: siteInfoData
                },
                success: function(response) {
                    if (response.success) {
                        showToast(response.data.message || 'Migration initiated successfully! Check your email for updates.', 'success');
                        
                        // Mark Step 3 as completed and validated
                        mgState.validatedSteps[3] = true;
                        updateStepState(3, 'completed');
                        
                        // Update button state
                        $initiateMigrationBtn.html('Migration In Progress...');
                        
                        // Redirect to URL if provided in response
                        if (response.data.url) {
                            setTimeout(() => {
                                window.location.href = response.data.url;
                            }, 1500); // Small delay to show the success message
                        }
                    } else {
                        // Show error
                        showToast(response.data.message || 'Failed to initiate migration. Please try again.', 'error');
                        
                        // Restore button
                        $initiateMigrationBtn.prop('disabled', false).html(`
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="mg-btn-icon">
                                <path d="M8 2v12M2 8h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            Initiate Migration
                        `);
                    }
                },
                error: function(xhr, status, error) {
                    showToast('An error occurred. Please try again.', 'error');
                    
                    // Restore button
                    $initiateMigrationBtn.prop('disabled', false).html(`
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="mg-btn-icon">
                            <path d="M8 2v12M2 8h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Initiate Migration
                    `);
                }
            });
        });
        
        // Allow Enter key to submit
        $emailInput.on('keypress', (e) => {
            if (e.which === 13) {
                $initiateMigrationBtn.click();
            }
        });
    }

    // ==================== STEP HEADER CLICK HANDLERS ====================

    function setupStepHeaderClicks() {
        // Add click handlers to all step headers for collapsed steps
        $('.mg-step-header').on('click', function(e) {
            // Don't trigger if clicking on buttons or links inside the header
            if ($(e.target).closest('button, a').length > 0) {
                return;
            }
            
            const $header = $(this);
            const $stepCard = $header.closest('.mg-step-card');
            const stepId = $stepCard.attr('id');
            
            // Extract step number from ID (e.g., 'mg-step2' -> 2)
            const stepNumber = parseInt(stepId.replace('mg-step', ''), 10);
            
            // Only expand if step is collapsed
            if ($stepCard.hasClass('mg-collapsed')) {
                expandStep(stepNumber);
            }
        });
    }

    // ==================== INITIALIZE ====================

    $(document).ready(function() {
        // Setup modals
        setupCopyKeyModal();
        setupGetKeyModal();
        
        // Setup steps
        setupStep1();
        setupStep2();
        setupStep3();
        
        // Setup step header click handlers
        setupStepHeaderClicks();
        
        // Initialize Step 3 overview with source URL
        updateStep3Overview();
        
        // Initialize step disabling based on active step
        // Check which step is currently active/expanded and disable subsequent steps
        const $step1 = $('#mg-step1');
        const $step2 = $('#mg-step2');
        const $step3 = $('#mg-step3');
        
        if ($step1.hasClass('mg-active') && !$step1.hasClass('mg-collapsed')) {
            // Step 1 is active and not collapsed, disable step 2 and 3
            disableSubsequentSteps(1);
        } else if ($step2.hasClass('mg-active') && !$step2.hasClass('mg-collapsed')) {
            // Step 2 is active and not collapsed, disable step 3
            disableSubsequentSteps(2);
        } else if ($step2.hasClass('mg-completed') && !$step2.hasClass('mg-collapsed')) {
            // Step 2 is completed and expanded, disable step 3
            disableSubsequentSteps(2);
        } else if ($step1.hasClass('mg-completed') && !$step1.hasClass('mg-collapsed')) {
            // Step 1 is completed and expanded, disable step 2 and 3
            disableSubsequentSteps(1);
        }
        
        // Close modals on Escape key
        $(document).on('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal('mg-copy-key-modal');
                closeModal('mg-get-key-modal');
                // Stop video when closing modal with Escape
                $('#mg-get-key-video-iframe').attr('src', '');
            }
        });
    });

})(jQuery);


