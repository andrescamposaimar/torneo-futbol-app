<?php
if (!defined('ABSPATH')) exit;

// Get the current site's migration key
$current_site_key = $this->bvinfo->getConnectionKey();
$api_url = $this->bvinfo->appUrl();
?>

<div class="wrap mg-onboarding-wrap">
    <!-- Hidden WordPress page title -->
    <h1 style="display:none;"><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <!-- Header -->
    <header class="mg-header">
        <div class="mg-header-content">
            <div class="mg-logo-section">
                <img src="<?php echo esc_url(plugins_url('../img/migrate-guru-logo.png', __FILE__)); ?>" alt="MigrateGuru" class="mg-logo">
            </div>
            <div class="mg-powered-by">
                <span class="mg-powered-text">Powered by</span>
                <img src="<?php echo esc_url(plugins_url('../img/blogvault-logo.png', __FILE__)); ?>" alt="BlogVault" class="mg-blogvault-logo">
            </div>
        </div>
</header>

    <div class="mg-container">
        <!-- Main Content -->
        <main class="mg-main-content">
            <!-- Page Title -->
            <div class="mg-page-title">
                <h1 class="mg-title">
                    <span class="mg-title-normal">Migrate your Site in</span>
                    <span class="mg-title-highlight">3 Simple Steps</span>
                </h1>
                <button id="mg-copy-key-btn" class="mg-btn-copy-key">
                    <img src="<?php echo esc_url(plugins_url('../img/icon-copy-key.svg', __FILE__)); ?>" alt="" class="mg-btn-icon">
                    Copy Key
                </button>
            </div>
            <input type="password" style="display: none;" autocomplete="new-password">
            <input type="password" style="display: none;" autocomplete="new-password">
            <!-- Steps Container -->
            <div class="mg-steps-container">
                <!-- Step 1: Install Plugin -->
                <div id="mg-step1" class="mg-step-card mg-active">
                    <div class="mg-step-header">
                        <div class="mg-step-number-badge" id="mg-step1-badge">1</div>
                        <div class="mg-step-info">
                            <h2 class="mg-step-title">Install MigrateGuru Plugin on both sites</h2>
                            <p class="mg-step-description">Install and activate MigrateGuru on both WordPress sites</p>
                        </div>
                        <div class="mg-step-status" id="mg-step1-status" style="display: none;">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M13.5 4.5L6 12L2.5 8.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Installed on both sites
                        </div>
                    </div>
                    <div class="mg-step-content">
                        <!-- Video Container -->
                        <div class="mg-step-video-container">
                            <iframe 
                                class="mg-step-video" 
                                src="https://www.youtube.com/embed/tp3z_fZIeRo?autoplay=1&mute=1&loop=1&playlist=tp3z_fZIeRo&controls=0&modestbranding=1&rel=0&playsinline=1&disablekb=1&fs=0&iv_load_policy=3&start=3&enablejsapi=1" 
                                frameborder="0" 
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                title="Migration Demo">
                            </iframe>
                            <div class="mg-video-overlay"></div>
                        </div>

                        <!-- Info Box -->
                        <div class="mg-info-box">
                            <div class="mg-info-header">
                                <img src="<?php echo esc_url(plugins_url('../img/icon-info.svg', __FILE__)); ?>" alt="" class="mg-info-icon">
                                <strong>Why both sites?</strong>
                            </div>
                            <p class="mg-info-text">MigrateGuru needs to be installed on both sites to securely transfer your content. The plugin on your old site will send the data, and the plugin on your new site will receive it.</p>
                        </div>

                        <!-- Action Button -->
                        <div class="mg-step-actions">
                            <button id="mg-step1-continue-btn" class="mg-btn-primary">
                                <img src="<?php echo esc_url(plugins_url('../img/icon-checkmark.svg', __FILE__)); ?>" alt="" class="mg-btn-icon">
                                Yes, I've Installed It
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Validate Migration Key -->
                <div id="mg-step2" class="mg-step-card mg-disabled">
                    <div class="mg-step-header">
                        <div class="mg-step-number-badge" id="mg-step2-badge">2</div>
                        <div class="mg-step-info">
                            <h2 class="mg-step-title">Validate Migration Key</h2>
                            <p class="mg-step-description">Enter the migration key from your destination site to establish connection</p>
                        </div>
                        <div class="mg-step-status" id="mg-step2-status" style="display: none;">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M13.5 4.5L6 12L2.5 8.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Key Validated
                        </div>
                    </div>
                    <div class="mg-step-content">
                        <div class="mg-form-group">
                            <div class="mg-form-label-row">
                                <label class="mg-form-label">Migration Key</label>
                                <button id="mg-get-key-btn" class="mg-btn-link" type="button">
                                    How to Get Key
                                    <img src="<?php echo esc_url(plugins_url('../img/icon-help.svg', __FILE__)); ?>" alt="" class="mg-btn-icon">
                                </button>
                            </div>
                            <div class="mg-key-input-wrapper">
                                <div class="mg-input-with-icon">
                                    <input type="password" id="mg-key-input" name="mg-key-input" autocomplete="off" class="mg-form-input mg-key-input" placeholder="Paste the key from your destination site">
                                    <button id="mg-toggle-step2-key-visibility" class="mg-btn-icon-only" type="button" aria-label="Toggle visibility">
                                        <svg class="mg-eye-icon-step2" width="16" height="16" viewBox="0 0 16 16" fill="none">
                                            <path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                            <circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.5"/>
                                        </svg>
                                        <svg class="mg-eye-off-icon-step2" width="16" height="16" viewBox="0 0 16 16" fill="none" style="display: none;">
                                            <path d="M1 1l14 14M6.5 6.5A2 2 0 0 0 9.5 9.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                            <path d="M13.5 10.5c.833-.833 1.5-2 1.5-2.5s-3-5-7-5c-1.167 0-2.167.333-3 .833M4 4.833C2.5 5.833 1 8 1 8s3 5 7 5c1.5 0 2.833-.667 4-1.667" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                        </svg>
                                    </button>
                                </div>
                                <button id="mg-validate-key-btn" class="mg-btn-primary">
                                    <img src="<?php echo esc_url(plugins_url('../img/icon-validate.svg', __FILE__)); ?>" alt="" class="mg-btn-icon">
                                    Validate Key
                                </button>
                            </div>
                            <div id="mg-key-error" class="mg-error-message" style="display: none;"></div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Initiate Migration -->
                <div id="mg-step3" class="mg-step-card mg-disabled">
                    <div class="mg-step-header">
                        <div class="mg-step-number-badge" id="mg-step3-badge">3</div>
                        <div class="mg-step-info">
                            <h2 class="mg-step-title">Initiate Migration</h2>
                            <p class="mg-step-description">Enter your email to start the migration process</p>
                        </div>
                        <div class="mg-step-status" id="mg-step3-status" style="display: none;">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M13.5 4.5L6 12L2.5 8.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Migration Started
                        </div>
                    </div>
                    <div class="mg-step-content">
                        <div class="mg-form-group">
                            <label class="mg-form-label">Email Address</label>
                            <input type="email" id="mg-email-input" class="mg-form-input" placeholder="your.email@example.com">
                            <p class="mg-form-hint">We'll send migration status updates to this email</p>
                            <div id="mg-email-error" class="mg-error-message" style="display: none;"></div>
                        </div>

                        <!-- Migration Overview -->
                        <div class="mg-migration-overview">
                            <div class="mg-overview-header">
                                <h3 class="mg-overview-title">Migration Overview</h3>
                                <p class="mg-overview-subtitle">Review your Old and New Sites before initiating migration</p>
                            </div>
                            <div class="mg-overview-cards">
                                <div class="mg-site-card" id="mg-step3-source-card">
                                    <div class="mg-site-card-body">
                                        <div class="mg-site-card-icon">
                                            <img src="<?php echo esc_url(plugins_url('../img/icon-wordpress.svg', __FILE__)); ?>" alt="">
                                        </div>
                                        <div class="mg-site-card-text">
                                            <p class="mg-site-label">Migrating From</p>
                                            <p class="mg-site-card-title">Old Site</p>
                                            <a href="#" class="mg-site-url mg-site-url-disabled" id="mg-step3-source-url" target="_blank" rel="noopener">—</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="mg-site-arrow">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                                        <path d="M5 12h14M13 6l6 6-6 6" stroke="#0097a7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <div class="mg-site-card mg-site-card-empty" id="mg-step3-destination-card">
                                    <div class="mg-site-card-body">
                                        <div class="mg-site-card-icon">
                                            <img src="<?php echo esc_url(plugins_url('../img/icon-wordpress.svg', __FILE__)); ?>" alt="">
                                        </div>
                                        <div class="mg-site-card-text">
                                            <p class="mg-site-label">Migrating To</p>
                                            <p class="mg-site-card-title" id="mg-step3-destination-title">New Site</p>
                                            <a href="#" class="mg-site-url mg-site-url-disabled" id="mg-step3-destination-url" target="_blank" rel="noopener">Validate the key in Step 2 to see the destination details.</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Info Box -->
                        <div class="mg-info-box">
                            <div class="mg-info-header">
                                <img src="<?php echo esc_url(plugins_url('../img/icon-info.svg', __FILE__)); ?>" alt="" class="mg-info-icon">
                                <strong>What happens next?</strong>
                            </div>
                            <p class="mg-info-text">Once you click "Initiate Migration", MigrateGuru will begin transferring your content, database, and media files from your old site to your new site. You'll receive email updates at each stage of the process.</p>
                        </div>

                        <!-- Action Button -->
                        <div class="mg-step-actions">
                            <button id="mg-initiate-migration-btn" class="mg-btn-primary">
                                <img src="<?php echo esc_url(plugins_url('../img/icon-rocket.svg', __FILE__)); ?>" alt="" class="mg-btn-icon">
                                Initiate Migration
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Sidebar -->
        <aside class="mg-sidebar">
            <div class="mg-sidebar-header">
                <h3 class="mg-sidebar-title">Effortless Cloning & Transfer</h3>
                <p class="mg-sidebar-subtitle">See a step-by-step guide on migrating your WordPress site</p>
            </div>

            <!-- Video Container -->
            <div class="mg-video-container">
                <iframe 
                    class="mg-migration-video" 
                    src="https://www.youtube.com/embed/ht1sBeqRTJY?rel=0&modestbranding=1&controls=1" 
                    frameborder="0" 
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" 
                    allowfullscreen
                    title="MigrateGuru Demo">
                </iframe>
                <div class="mg-video-overlay-clickable"></div>
            </div>

            <!-- Help Links -->
            <div class="mg-help-links">
                <a href="https://migrateguru.freshdesk.com/support/solutions/33000052052" target="_blank" class="mg-help-link">
                    <div class="mg-help-link-content">
                        <div class="mg-help-link-title">📖 Help Docs</div>
                        <div class="mg-help-link-desc">Check out the Migration Guide to help answer your queries.</div>
                    </div>
                    <img src="<?php echo esc_url(plugins_url('../img/icon-external.svg', __FILE__)); ?>" alt="" class="mg-help-link-icon">
                </a>
                <a href="https://migrateguru.freshdesk.com/support/tickets/new" target="_blank" class="mg-help-link">
                    <div class="mg-help-link-content">
                        <div class="mg-help-link-title">🎧 Support</div>
                        <div class="mg-help-link-desc">Need Help? Contact us & we'll get back to you.</div>
                    </div>
                    <img src="<?php echo esc_url(plugins_url('../img/icon-external.svg', __FILE__)); ?>" alt="" class="mg-help-link-icon">
                </a>
                <a href="https://migrateguru.freshdesk.com/support/solutions/33000046011" target="_blank" class="mg-help-link">
                    <div class="mg-help-link-content">
                        <div class="mg-help-link-title">❓ FAQ</div>
                        <div class="mg-help-link-desc">Find answers to frequently asked questions.</div>
                    </div>
                    <img src="<?php echo esc_url(plugins_url('../img/icon-external.svg', __FILE__)); ?>" alt="" class="mg-help-link-icon">
                </a>
                <a href="https://wordpress.org/support/plugin/migrate-guru/reviews/#new-post" target="_blank" class="mg-help-link">
                    <div class="mg-help-link-content">
                        <div class="mg-help-link-title">⭐ Rate Us</div>
                        <div class="mg-help-link-desc">Share your experience and help others discover MigrateGuru.</div>
                    </div>
                    <img src="<?php echo esc_url(plugins_url('../img/icon-external.svg', __FILE__)); ?>" alt="" class="mg-help-link-icon">
			</a>
		</div>
        </aside>
    </div>
</div><!-- .wrap -->

<!-- Toast Container (outside .wrap for proper z-index) -->
<div id="mg-toast-container" class="mg-toast-container"></div>

<!-- Copy Key Modal -->
<div id="mg-copy-key-modal" class="mg-modal">
    <div class="mg-modal-overlay"></div>
    <div class="mg-modal-content">
        <div class="mg-modal-header">
            <h3 class="mg-modal-title">Your Migration Key</h3>
            <p class="mg-modal-description">Copy this key and paste it on the Old Site (the one you want to Migrate From).</p>
            <button class="mg-modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="mg-modal-body">
            
            <div class="mg-form-group">
                <label class="mg-form-label">Migration Key</label>
                <div class="mg-key-input-wrapper">
                    <div class="mg-input-with-icon">
                        <input type="password" id="mg-modal-key-input" class="mg-form-input mg-key-input" readonly value="<?php echo esc_attr($current_site_key); ?>">
                        <button id="mg-toggle-key-visibility" class="mg-btn-icon-only" type="button" aria-label="Toggle visibility">
                            <svg id="mg-eye-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                <circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            <svg id="mg-eye-off-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" style="display: none;">
                                <path d="M1 1l14 14M6.5 6.5A2 2 0 0 0 9.5 9.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M13.5 10.5c.833-.833 1.5-2 1.5-2.5s-3-5-7-5c-1.167 0-2.167.333-3 .833M4 4.833C2.5 5.833 1 8 1 8s3 5 7 5c1.5 0 2.833-.667 4-1.667" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>
                    <button id="mg-copy-key-from-modal" class="mg-btn-primary" type="button">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <rect x="5" y="5" width="9" height="9" rx="1" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M11 3H3a1 1 0 0 0-1 1v8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        Copy Key
                    </button>
                </div>
                <div class="mg-key-input-hint">
                    Do not paste this key back in step 2 of this site.
                </div>
            </div>

            <div class="mg-warning-box">
                <div class="mg-warning-header">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M8 1L1 14h14L8 1z" stroke="#D97706" stroke-width="1.5" stroke-linejoin="round"/>
                        <path d="M8 6v3M8 11h.01" stroke="#D97706" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    <strong>Important</strong>
                </div>
                <ul class="mg-warning-list">
                    <li>Since you're copying the key from this site, <strong>we assume this is the site you're Migrating To.</strong></li>
                    <li>In a new browser tab, <strong>Open the New Site (The one you are 'Migrating To')</strong> and Copy Key</li>
                    <li>Paste this key in the MigrateGuru plugin on that site</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Get Key Modal -->
<div id="mg-get-key-modal" class="mg-modal">
    <div class="mg-modal-overlay"></div>
    <div class="mg-modal-content">
        <div class="mg-modal-header">
            <h3 class="mg-modal-title">How to Get Your Migration Key</h3>
            <button class="mg-modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="mg-modal-body">
            <div class="mg-steps-list">
                <div class="mg-instruction-step">
                    <span class="mg-step-num">1.</span>
                    <p>Install and activate MigrateGuru on the new site (The one you are Migrating To)</p>
                </div>
                <div class="mg-instruction-step">
                    <span class="mg-step-num">2.</span>
                    <p>Copy the migration key from the top header of the new site (The one you are Migrating To)</p>
        </div>
                <div class="mg-instruction-step">
                    <span class="mg-step-num">3.</span>
                    <p>Paste the key on the old site (The one you are Migrating From) and click Validate</p>
            </div>
        </div>

            <div style="margin: 24px 0; position: relative;">
                <iframe 
                    id="mg-get-key-video-iframe"
                    class="mg-get-key-video-player"
                    src="" 
                    data-src="https://www.youtube.com/embed/9BUW4YwBYSA?autoplay=1&mute=1&loop=1&playlist=9BUW4YwBYSA&controls=0&modestbranding=1&rel=0&playsinline=1&disablekb=1&fs=0&iv_load_policy=3&start=3&enablejsapi=1"
                    frameborder="0" 
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                    title="How to Get Key"
                    style="width: 100%; aspect-ratio: 16/9; display: block; border: none; outline: none; border-radius: 8px;">
                </iframe>
                <div class="mg-video-overlay"></div>
            </div>

            <div class="mg-warning-box">
                <div class="mg-warning-header">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M8 1L1 14h14L8 1z" stroke="#D97706" stroke-width="1.5" stroke-linejoin="round"/>
                        <path d="M8 6v3M8 11h.01" stroke="#D97706" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    <strong>Important</strong>
                </div>
                <p class="mg-warning-text">Make sure you're copying the key from your <strong>New Site</strong> (The one you are 'Migrating To') and pasting it on your <strong>Old Site</strong> (The one you are 'Migrating From').</p>
            </div>

            <div class="mg-modal-footer">
                <button id="mg-got-it-btn" class="mg-btn-primary">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="mg-btn-icon">
                        <path d="M13.5 4.5L6 12 2.5 8.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Got It
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden form with site info -->
<form id="mg-site-info-form" style="display: none;">
    <?php echo $this->siteInfoTags(); ?>
    <input type="hidden" id="mg-current-site-key" value="<?php echo esc_attr($current_site_key); ?>">
</form>

<!-- Pass WordPress AJAX URL and nonce to JavaScript -->
<script type="text/javascript">
    var mgOnboarding = {
        ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('mg_onboarding_nonce')); ?>',
        currentSiteKey: '<?php echo esc_js($current_site_key); ?>',
        apiUrl: '<?php echo esc_js($api_url); ?>',
        currentSiteUrl: '<?php echo esc_js($this->siteinfo->siteurl()); ?>'
    };
</script>