<?php
/**
 * Storedash Unified Admin Interface
 *
 * Consolidated admin interface for all Storedash WP settings and configurations
 *
 * @package Storedash
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Admin {

	/**
	 * Assets manager
	 */
	private $assets;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Initialize assets manager
		$this->assets = new StoreDash_Admin_Assets();

		// Admin hooks
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_head', array( $this, 'remove_admin_notices' ) );

		// AJAX handlers for cart settings
		add_action( 'wp_ajax_storedash_wp_save_cart_settings', array( $this, 'ajax_save_cart_settings' ) );
		add_action( 'wp_ajax_storedash_wp_test_webhook', array( $this, 'ajax_test_webhook' ) );

		// AJAX handlers for diagnostics
		add_action( 'wp_ajax_storedash_wp_run_diagnostics', array( $this, 'ajax_run_diagnostics' ) );
		add_action( 'wp_ajax_storedash_wp_get_system_info', array( $this, 'ajax_get_system_info' ) );
		add_action( 'wp_ajax_storedash_wp_copy_system_info', array( $this, 'ajax_copy_system_info' ) );
		add_action( 'wp_ajax_storedash_wp_download_system_info', array( $this, 'ajax_download_system_info' ) );
		add_action( 'wp_ajax_storedash_wp_export_system_info', array( $this, 'ajax_export_system_info' ) );
		add_action( 'wp_ajax_storedash_wp_check_endpoints', array( $this, 'ajax_check_endpoints' ) );

		// Register settings
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Remove all admin notices on Storedash WP pages (keeps UI clean)
	 */
	public function remove_admin_notices() {
		$screen = get_current_screen();

		if ( ! $screen || strpos( $screen->id, 'storedash-wp' ) === false ) {
			return;
		}

		// Remove all admin notices and update nags
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		// Cart tracking, marketing opt-in, abandonment time and retention days are
		// provisioned from the Storedash dashboard (storedash/v1/carts/settings); the
		// store ID and webhook signing secret via storedash/v1/store-config. None of
		// them are merchant-editable here. Only Clean Uninstall is a local setting.
		register_setting( 'storedash_wp_settings', 'storedash_clean_uninstall', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Storedash WP', 'storedash' ),
			__( 'Storedash WP', 'storedash' ),
			'manage_woocommerce',
			'storedash-wp',
			array( $this, 'render_admin_page' ),
			'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAyMCAyMCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTEwIDJMMiA2VjE0TDEwIDE4TDE4IDE0VjZMMTAgMloiIHN0cm9rZT0iIzcyRTNBRCIgc3Ryb2tlLXdpZHRoPSIyIiBzdHJva2UtbGluZWNhcD0icm91bmQiIHN0cm9rZS1saW5lam9pbj0icm91bmQiLz4KPHBhdGggZD0iTTIgNkwxMCAxME0xMCAxMEwxOCA2TTEwIDEwVjE4IiBzdHJva2U9IiM3MkUzQUQiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIi8+Cjwvc3ZnPgo=',
			56
		);
	}

	/**
	 * Render admin page
	 */
	public function render_admin_page() {
		?>
		<div class="storedash-wp-admin-app">
			<!-- Header -->
			<div class="storedash-wp-admin-header">
				<div class="storedash-wp-admin-header__wrapper">
					<div class="storedash-wp-admin-header__left">
						<div class="storedash-wp-admin-header__logo">
							<img src="<?php echo esc_url( STOREDASH_URL . 'assets/images/storedash-logo.webp' ); ?>" alt="Storedash WP" class="storedash-wp-admin-header__logo-image">
						</div>
						<span class="storedash-wp-admin-header__version">v<?php echo esc_html( STOREDASH_VERSION ); ?></span>
					</div>
					<div class="storedash-wp-admin-header__right">
						<p style="margin: 0; font-size: 14px; color: #6b7280;">
							<?php esc_html_e( 'Helper plugin for Storedash API endpoints and webhooks', 'storedash' ); ?>
						</p>
					</div>
				</div>
			</div>

			<!-- Navigation -->
			<div class="storedash-wp-admin-nav">
				<div class="storedash-wp-admin-nav__wrapper">
					<ul class="storedash-wp-admin-nav__list" role="tablist">
						<li class="storedash-wp-admin-nav__item" role="presentation">
							<a href="#cart-settings" class="storedash-wp-admin-nav__link storedash-wp-admin-nav__link--active" data-tab="cart-settings" role="tab" aria-selected="true" aria-controls="cart-settings-panel">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
									<circle cx="12" cy="12" r="3"></circle>
									<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
								</svg>
								<span><?php esc_html_e( 'Settings', 'storedash' ); ?></span>
							</a>
						</li>
						<li class="storedash-wp-admin-nav__item" role="presentation">
							<a href="#diagnostics" class="storedash-wp-admin-nav__link" data-tab="diagnostics" role="tab" aria-selected="false" aria-controls="diagnostics-panel">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
									<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
									<polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
									<line x1="12" y1="22.08" x2="12" y2="12"></line>
								</svg>
								<span><?php esc_html_e( 'Diagnostics', 'storedash' ); ?></span>
							</a>
						</li>
						</ul>
				</div>
			</div>

			<!-- Content -->
			<div class="storedash-wp-admin-content">
				<!-- Cart Settings Tab -->
				<div class="storedash-wp-tabs__panel storedash-wp-tabs__panel--active" id="cart-settings-panel" data-panel="cart-settings" role="tabpanel" aria-labelledby="cart-settings-tab">
					<?php $this->render_cart_settings_panel(); ?>
				</div>

				<!-- Diagnostics Tab -->
				<div class="storedash-wp-tabs__panel" id="diagnostics-panel" data-panel="diagnostics" role="tabpanel" aria-labelledby="diagnostics-tab">
					<?php $this->render_diagnostics_panel(); ?>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Render Cart Settings Panel
	 */
	private function render_cart_settings_panel() {
		$save_icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>';
		?>
		<!-- Panel Header -->
		<div class="storedash-wp-panel-header">
			<div class="storedash-wp-panel-header__content">
				<h1 class="storedash-wp-panel-header__title"><?php esc_html_e( 'Settings', 'storedash' ); ?></h1>
				<p class="storedash-wp-panel-header__description"><?php esc_html_e( 'Cart tracking and marketing opt-in are managed from your Storedash dashboard.', 'storedash' ); ?></p>
			</div>
			<div class="storedash-wp-panel-header__actions">
				<?php
				StoreDash_Admin_Components::button(
					array(
						'text'    => __( 'Save Settings', 'storedash' ),
						'variant' => 'primary',
						'icon'    => $save_icon,
						'id'      => 'storedash-wp-save-cart-settings',
					)
				);
				?>
			</div>
		</div>

		<div class="storedash-wp-cards-grid">
			<!-- General Settings Card -->
			<div class="storedash-wp-card storedash-wp-card--full">
				<div class="storedash-wp-card__header">
					<h3 class="storedash-wp-card__header-title"><?php esc_html_e( 'General Settings', 'storedash' ); ?></h3>
				</div>
				<div class="storedash-wp-card__body">
					<div class="storedash-wp-field-group">
						<label class="storedash-wp-field-group__label">
							<?php esc_html_e( 'Clean Uninstall', 'storedash' ); ?>
						</label>
						<div style="margin-bottom: 16px;">
							<?php
							StoreDash_Admin_Components::toggle(
								array(
									'name'    => 'clean_uninstall',
									'id'      => 'clean_uninstall',
									'checked' => (bool) get_option( 'storedash_clean_uninstall', false ),
								)
							);
							?>
							<span style="margin-left: 12px;"><?php esc_html_e( 'Remove all data when plugin is deleted', 'storedash' ); ?></span>
						</div>
						<p class="storedash-wp-help-text" style="color: #dc2626;">
							<?php esc_html_e( 'When enabled, deleting this plugin will remove ALL Storedash data including database tables, settings, and cached data. This cannot be undone.', 'storedash' ); ?>
						</p>
					</div>
				</div>
			</div>
		</div>

		<div id="storedash-wp-cart-message" class="storedash-wp-message" style="display: none; margin-top: 24px;"></div>
		<?php
	}

	/**
	 * Render Diagnostics Panel
	 */
	private function render_diagnostics_panel() {
		$system_info   = $this->get_system_info();
		$copy_icon     = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
		$download_icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>';
		$export_icon   = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>';
		?>
		<!-- Panel Header -->
		<div class="storedash-wp-panel-header storedash-wp-panel-header--no-actions">
			<div class="storedash-wp-panel-header__content">
				<h1 class="storedash-wp-panel-header__title"><?php esc_html_e( 'Diagnostics', 'storedash' ); ?></h1>
				<p class="storedash-wp-panel-header__description"><?php esc_html_e( 'Comprehensive system information and connectivity diagnostics', 'storedash' ); ?></p>
			</div>
		</div>

		<div id="storedash-wp-admin-message" class="storedash-wp-message" style="display: none; margin-bottom: 24px;"></div>

		<div class="storedash-wp-cards-grid">
			<!-- Quick Actions Card -->
			<div class="storedash-wp-card storedash-wp-card--full storedash-wp-diagnostics-section" data-section="quick-actions">
				<div class="storedash-wp-card__header">
					<h3 class="storedash-wp-card__header-title"><?php esc_html_e( 'Quick Actions', 'storedash' ); ?></h3>
					<button type="button" class="storedash-wp-section-toggle" aria-expanded="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="18 15 12 9 6 15"></polyline>
						</svg>
					</button>
				</div>
				<div class="storedash-wp-card__body storedash-wp-section-content">
					<div class="storedash-wp-actions-list">
						<?php
						StoreDash_Admin_Components::button(
							array(
								'text'    => __( 'Copy System Info', 'storedash' ),
								'variant' => 'secondary',
								'icon'    => $copy_icon,
								'id'      => 'storedash-wp-copy-system-info',
							)
						);
						?>
						<?php
						StoreDash_Admin_Components::button(
							array(
								'text'    => __( 'Download System Info', 'storedash' ),
								'variant' => 'secondary',
								'icon'    => $download_icon,
								'id'      => 'storedash-wp-download-system-info',
							)
						);
						?>
						<?php
						StoreDash_Admin_Components::button(
							array(
								'text'    => __( 'Export System Info (JSON)', 'storedash' ),
								'variant' => 'secondary',
								'icon'    => $export_icon,
								'id'      => 'storedash-wp-export-system-info',
							)
						);
						?>
					</div>
				</div>
			</div>

			<!-- System Overview Card -->
			<div class="storedash-wp-card storedash-wp-card--full storedash-wp-diagnostics-section" data-section="system-overview">
				<div class="storedash-wp-card__header">
					<h3 class="storedash-wp-card__header-title"><?php esc_html_e( 'System Overview', 'storedash' ); ?></h3>
					<button type="button" class="storedash-wp-section-toggle" aria-expanded="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="18 15 12 9 6 15"></polyline>
						</svg>
					</button>
				</div>
				<div class="storedash-wp-card__body storedash-wp-section-content">
					<div class="storedash-wp-table-wrapper">
						<table class="storedash-wp-table">
							<tbody>
								<tr>
									<td><strong><?php esc_html_e( 'WordPress Version', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['system_overview']['wordpress_version'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Site URL', 'storedash' ); ?></strong></td>
									<td><code><?php echo esc_html( $system_info['system_overview']['site_url'] ); ?></code></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Home URL', 'storedash' ); ?></strong></td>
									<td><code><?php echo esc_html( $system_info['system_overview']['home_url'] ); ?></code></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Active Theme', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['system_overview']['active_theme']['name'] ); ?> <?php echo esc_html( $system_info['system_overview']['active_theme']['version'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Active Plugins', 'storedash' ); ?></strong></td>
									<td>
										<?php echo esc_html( $system_info['system_overview']['active_plugins_count'] ); ?>
										<button type="button" class="storedash-wp-btn storedash-wp-btn-secondary storedash-wp-btn-small" style="margin-left: 8px;" onclick="jQuery(this).next('.storedash-wp-plugins-list').slideToggle();">
											<?php esc_html_e( 'Show List', 'storedash' ); ?>
										</button>
										<div class="storedash-wp-plugins-list" style="display: none; margin-top: 12px;">
											<ul style="margin: 0; padding-left: 20px;">
												<?php foreach ( $system_info['system_overview']['active_plugins'] as $plugin ) : ?>
													<li><?php echo esc_html( $plugin['name'] ); ?> <?php echo esc_html( $plugin['version'] ); ?></li>
												<?php endforeach; ?>
											</ul>
										</div>
									</td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Multisite', 'storedash' ); ?></strong></td>
									<td><?php echo $system_info['system_overview']['is_multisite'] ? '<span class="storedash-wp-badge storedash-wp-badge-attribute">' . esc_html__( 'Yes', 'storedash' ) . '</span>' : esc_html__( 'No', 'storedash' ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Language', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['system_overview']['language'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Permalink Structure', 'storedash' ); ?></strong></td>
									<td><code><?php echo esc_html( $system_info['system_overview']['permalink_structure'] ?: __( 'Plain', 'storedash' ) ); ?></code></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'WP_CACHE', 'storedash' ); ?></strong></td>
									<td><?php echo $system_info['system_overview']['wp_cache'] ? '<span class="storedash-wp-text-success">✓ ' . esc_html__( 'Enabled', 'storedash' ) . '</span>' : '<span class="storedash-wp-text-warning">✗ ' . esc_html__( 'Disabled', 'storedash' ) . '</span>'; ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'External Object Cache', 'storedash' ); ?></strong></td>
									<td><?php echo $system_info['system_overview']['external_object_cache'] ? '<span class="storedash-wp-text-success">✓ ' . esc_html__( 'Yes', 'storedash' ) . '</span>' : '<span class="storedash-wp-text-warning">✗ ' . esc_html__( 'No', 'storedash' ) . '</span>'; ?></td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- PHP Environment Card -->
			<div class="storedash-wp-card storedash-wp-card--full storedash-wp-diagnostics-section" data-section="php-environment">
				<div class="storedash-wp-card__header">
					<h3 class="storedash-wp-card__header-title"><?php esc_html_e( 'PHP Environment', 'storedash' ); ?></h3>
					<button type="button" class="storedash-wp-section-toggle" aria-expanded="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="18 15 12 9 6 15"></polyline>
						</svg>
					</button>
				</div>
				<div class="storedash-wp-card__body storedash-wp-section-content">
					<div class="storedash-wp-table-wrapper">
						<table class="storedash-wp-table">
							<tbody>
								<tr>
									<td><strong><?php esc_html_e( 'PHP Version', 'storedash' ); ?></strong></td>
									<td><code><?php echo esc_html( $system_info['php_environment']['version'] ); ?></code></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'PHP SAPI', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['php_environment']['sapi'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Memory Limit', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['php_environment']['memory_limit'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Max Execution Time', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['php_environment']['max_execution_time'] ); ?> <?php esc_html_e( 'seconds', 'storedash' ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Max Input Vars', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['php_environment']['max_input_vars'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Post Max Size', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['php_environment']['post_max_size'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Upload Max Filesize', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['php_environment']['upload_max_filesize'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Allow URL FOpen', 'storedash' ); ?></strong></td>
									<td><?php echo $system_info['php_environment']['allow_url_fopen'] ? '<span class="storedash-wp-text-success">✓ ' . esc_html__( 'Enabled', 'storedash' ) . '</span>' : '<span class="storedash-wp-text-warning">✗ ' . esc_html__( 'Disabled', 'storedash' ) . '</span>'; ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Loaded Extensions', 'storedash' ); ?></strong></td>
									<td>
										<?php echo esc_html( count( $system_info['php_environment']['loaded_extensions'] ) ); ?>
										<button type="button" class="storedash-wp-btn storedash-wp-btn-secondary storedash-wp-btn-small" style="margin-left: 8px;" onclick="jQuery(this).next('.storedash-wp-extensions-list').slideToggle();">
											<?php esc_html_e( 'Show List', 'storedash' ); ?>
										</button>
										<div class="storedash-wp-extensions-list" style="display: none; margin-top: 12px;">
											<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px;">
												<?php foreach ( $system_info['php_environment']['loaded_extensions'] as $ext ) : ?>
													<code style="font-size: 12px;"><?php echo esc_html( $ext ); ?></code>
												<?php endforeach; ?>
											</div>
										</div>
									</td>
								</tr>
								<?php if ( $system_info['php_environment']['disabled_functions'] ) : ?>
								<tr>
									<td><strong><?php esc_html_e( 'Disabled Functions', 'storedash' ); ?></strong></td>
									<td><code><?php echo esc_html( $system_info['php_environment']['disabled_functions'] ); ?></code></td>
								</tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- Server Environment Card -->
			<div class="storedash-wp-card storedash-wp-card--full storedash-wp-diagnostics-section" data-section="server-environment">
				<div class="storedash-wp-card__header">
					<h3 class="storedash-wp-card__header-title"><?php esc_html_e( 'Server Environment', 'storedash' ); ?></h3>
					<button type="button" class="storedash-wp-section-toggle" aria-expanded="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="18 15 12 9 6 15"></polyline>
						</svg>
					</button>
				</div>
				<div class="storedash-wp-card__body storedash-wp-section-content">
					<div class="storedash-wp-table-wrapper">
						<table class="storedash-wp-table">
							<tbody>
								<tr>
									<td><strong><?php esc_html_e( 'Server Software', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['server_environment']['server_software'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Server OS', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['server_environment']['server_os'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Server IP', 'storedash' ); ?></strong></td>
									<td><code><?php echo esc_html( $system_info['server_environment']['server_ip'] ); ?></code></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Server Timezone', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['server_environment']['server_timezone'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Server Time', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['server_environment']['server_time'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Document Root', 'storedash' ); ?></strong></td>
									<td><code><?php echo esc_html( $system_info['server_environment']['document_root'] ); ?></code></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Disk Free Space', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['server_environment']['disk_free_space'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Disk Total Space', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['server_environment']['disk_total_space'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Server Protocol', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['server_environment']['server_protocol'] ); ?></td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- Database Information Card -->
			<div class="storedash-wp-card storedash-wp-card--full storedash-wp-diagnostics-section" data-section="database-info">
				<div class="storedash-wp-card__header">
					<h3 class="storedash-wp-card__header-title"><?php esc_html_e( 'Database Information', 'storedash' ); ?></h3>
					<button type="button" class="storedash-wp-section-toggle" aria-expanded="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="18 15 12 9 6 15"></polyline>
						</svg>
					</button>
				</div>
				<div class="storedash-wp-card__body storedash-wp-section-content">
					<div class="storedash-wp-table-wrapper">
						<table class="storedash-wp-table">
							<tbody>
								<tr>
									<td><strong><?php esc_html_e( 'Database Version', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['database_info']['version'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Database Name', 'storedash' ); ?></strong></td>
									<td><code><?php echo esc_html( $system_info['database_info']['name'] ); ?></code></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Database Charset', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['database_info']['charset'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Database Collate', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['database_info']['collate'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Database Size', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['database_info']['size'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Table Count', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['database_info']['table_count'] ); ?></td>
								</tr>
								<tr>
									<td colspan="2">
										<strong style="display: block; margin-bottom: 8px;"><?php esc_html_e( 'Storedash Database Tables', 'storedash' ); ?></strong>
										<table class="storedash-wp-table storedash-wp-table--nested" style="margin: 0;">
											<thead>
												<tr>
													<th style="width: 45%;"><?php esc_html_e( 'Table', 'storedash' ); ?></th>
													<th style="width: 30%;"><?php esc_html_e( 'Status', 'storedash' ); ?></th>
													<th style="width: 25%; text-align: right;"><?php esc_html_e( 'Rows', 'storedash' ); ?></th>
												</tr>
											</thead>
											<tbody>
												<?php foreach ( $system_info['database_info']['storedash_tables'] as $table => $info ) : ?>
													<tr>
														<td>
															<code style="font-size: 11px;"><?php echo esc_html( $table ); ?></code>
															<br>
															<small class="storedash-wp-text-muted"><?php echo esc_html( $info['description'] ); ?></small>
														</td>
														<td>
															<?php if ( $info['exists'] ) : ?>
																<span class="storedash-wp-badge storedash-wp-badge--success">✓ <?php esc_html_e( 'Exists', 'storedash' ); ?></span>
															<?php else : ?>
																<span class="storedash-wp-badge storedash-wp-badge--warning">✗ <?php esc_html_e( 'Missing', 'storedash' ); ?></span>
															<?php endif; ?>
														</td>
														<td style="text-align: right;">
															<?php if ( $info['exists'] ) : ?>
																<?php echo esc_html( number_format_i18n( $info['row_count'] ) ); ?>
															<?php else : ?>
																<span class="storedash-wp-text-muted">—</span>
															<?php endif; ?>
														</td>
													</tr>
												<?php endforeach; ?>
											</tbody>
										</table>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- Network & Connectivity Card -->
			<div class="storedash-wp-card storedash-wp-card--full storedash-wp-diagnostics-section" data-section="network-info">
				<div class="storedash-wp-card__header">
					<h3 class="storedash-wp-card__header-title"><?php esc_html_e( 'Network & Connectivity', 'storedash' ); ?></h3>
					<button type="button" class="storedash-wp-section-toggle" aria-expanded="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="18 15 12 9 6 15"></polyline>
						</svg>
					</button>
				</div>
				<div class="storedash-wp-card__body storedash-wp-section-content">
					<div class="storedash-wp-table-wrapper">
						<table class="storedash-wp-table">
							<tbody>
								<tr>
									<td><strong><?php esc_html_e( 'Server IP', 'storedash' ); ?></strong></td>
									<td><code><?php echo esc_html( $system_info['network_info']['server_ip'] ); ?></code></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Outbound IP', 'storedash' ); ?></strong></td>
									<td><code><?php echo esc_html( $system_info['network_info']['outbound_ip'] ); ?></code> <span style="color: #6b7280; font-size: 12px;"><?php esc_html_e( '(not checked on page load to avoid slowdown)', 'storedash' ); ?></span></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'cURL Enabled', 'storedash' ); ?></strong></td>
									<td><?php echo $system_info['network_info']['curl_enabled'] ? '<span class="storedash-wp-text-success">✓ ' . esc_html__( 'Yes', 'storedash' ) . '</span>' : '<span class="storedash-wp-text-warning">✗ ' . esc_html__( 'No', 'storedash' ) . '</span>'; ?></td>
								</tr>
								<?php if ( $system_info['network_info']['curl_enabled'] ) : ?>
								<tr>
									<td><strong><?php esc_html_e( 'cURL Version', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['network_info']['curl_info']['version'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'SSL Version', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['network_info']['curl_info']['ssl_version'] ); ?></td>
								</tr>
								<?php endif; ?>
								<tr>
									<td><strong><?php esc_html_e( 'OpenSSL Enabled', 'storedash' ); ?></strong></td>
									<td><?php echo $system_info['network_info']['openssl_enabled'] ? '<span class="storedash-wp-text-success">✓ ' . esc_html__( 'Yes', 'storedash' ) . '</span>' : '<span class="storedash-wp-text-warning">✗ ' . esc_html__( 'No', 'storedash' ) . '</span>'; ?></td>
								</tr>
								<?php if ( $system_info['network_info']['openssl_enabled'] ) : ?>
								<tr>
									<td><strong><?php esc_html_e( 'OpenSSL Version', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['network_info']['openssl_version'] ); ?></td>
								</tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- WooCommerce Information Card -->
			<?php if ( $system_info['woocommerce_info']['installed'] ) : ?>
			<div class="storedash-wp-card storedash-wp-card--full storedash-wp-diagnostics-section" data-section="woocommerce-info">
				<div class="storedash-wp-card__header">
					<h3 class="storedash-wp-card__header-title"><?php esc_html_e( 'WooCommerce Information', 'storedash' ); ?></h3>
					<button type="button" class="storedash-wp-section-toggle" aria-expanded="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="18 15 12 9 6 15"></polyline>
						</svg>
					</button>
				</div>
				<div class="storedash-wp-card__body storedash-wp-section-content">
					<div class="storedash-wp-table-wrapper">
						<table class="storedash-wp-table">
							<tbody>
								<tr>
									<td><strong><?php esc_html_e( 'WooCommerce Version', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['woocommerce_info']['version'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Database Version', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['woocommerce_info']['database_version'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Currency', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['woocommerce_info']['currency'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Base Location', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['woocommerce_info']['base_location']['country'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Tax Enabled', 'storedash' ); ?></strong></td>
									<td><?php echo $system_info['woocommerce_info']['tax_enabled'] ? '<span class="storedash-wp-text-success">✓ ' . esc_html__( 'Yes', 'storedash' ) . '</span>' : '<span class="storedash-wp-text-warning">✗ ' . esc_html__( 'No', 'storedash' ) . '</span>'; ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'WooCommerce Pages', 'storedash' ); ?></strong></td>
									<td>
										<?php foreach ( $system_info['woocommerce_info']['pages'] as $page_name => $page ) : ?>
											<div style="margin-bottom: 4px;">
												<?php echo esc_html( ucfirst( $page_name ) ); ?>: 
												<?php if ( $page['exists'] && $page['published'] ) : ?>
													<span class="storedash-wp-text-success">✓ <?php esc_html_e( 'Published', 'storedash' ); ?> (ID: <?php echo esc_html( $page['id'] ); ?>)</span>
												<?php elseif ( $page['exists'] ) : ?>
													<span class="storedash-wp-text-warning">⚠ <?php esc_html_e( 'Exists but not published', 'storedash' ); ?> (ID: <?php echo esc_html( $page['id'] ); ?>)</span>
												<?php else : ?>
													<span class="storedash-wp-text-warning">✗ <?php esc_html_e( 'Missing', 'storedash' ); ?></span>
												<?php endif; ?>
											</div>
										<?php endforeach; ?>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<!-- StoreDash Plugin Information Card -->
			<div class="storedash-wp-card storedash-wp-card--full storedash-wp-diagnostics-section" data-section="storedash-info">
				<div class="storedash-wp-card__header">
					<h3 class="storedash-wp-card__header-title"><?php esc_html_e( 'Storedash Plugin', 'storedash' ); ?></h3>
					<button type="button" class="storedash-wp-section-toggle" aria-expanded="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="18 15 12 9 6 15"></polyline>
						</svg>
					</button>
				</div>
				<div class="storedash-wp-card__body storedash-wp-section-content">
					<div class="storedash-wp-table-wrapper">
						<table class="storedash-wp-table">
							<tbody>
								<tr>
									<td><strong><?php esc_html_e( 'Version', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['storedash_info']['version'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Cart Tracking', 'storedash' ); ?></strong></td>
									<td><?php echo $system_info['storedash_info']['cart_tracking_enabled'] ? '<span class="storedash-wp-text-success">✓ ' . esc_html__( 'Enabled', 'storedash' ) . '</span>' : '<span class="storedash-wp-text-warning">✗ ' . esc_html__( 'Disabled', 'storedash' ) . '</span>'; ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Webhook URL', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['storedash_info']['webhook_url'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Store ID', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['storedash_info']['store_id'] ); ?></td>
								</tr>
								</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- Webhook Configuration Card -->
			<?php
			$webhook_secret   = get_option( 'woodash_webhook_secret', '' );
			$cart_webhook_url = get_option( 'woodash_cart_webhook_url', 'https://webhooks.storedash.io/yvrrywi5uld4jq' );
			$masked_secret    = '';
			if ( $webhook_secret ) {
				$len = strlen( $webhook_secret );
				if ( $len > 8 ) {
					$masked_secret = substr( $webhook_secret, 0, 4 ) . str_repeat( '*', $len - 8 ) . substr( $webhook_secret, -4 );
				} else {
					$masked_secret = str_repeat( '*', $len );
				}
			}
			?>
			<div class="storedash-wp-card storedash-wp-card--full storedash-wp-diagnostics-section" data-section="webhook-config">
				<div class="storedash-wp-card__header">
					<h3 class="storedash-wp-card__header-title"><?php esc_html_e( 'Webhook Configuration', 'storedash' ); ?></h3>
					<button type="button" class="storedash-wp-section-toggle" aria-expanded="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="18 15 12 9 6 15"></polyline>
						</svg>
					</button>
				</div>
				<div class="storedash-wp-card__body storedash-wp-section-content">
					<p style="margin-bottom: 16px; color: #666;">
						<?php esc_html_e( 'These settings control how your store communicates with Storedash. If cart tracking or waitlist features are not working, check that the webhook secret matches what Storedash expects.', 'storedash' ); ?>
					</p>
					<div class="storedash-wp-table-wrapper">
						<table class="storedash-wp-table">
							<tbody>
								<tr>
									<td><strong><?php esc_html_e( 'Store ID', 'storedash' ); ?></strong></td>
									<td><code><?php echo esc_html( get_option( 'woodash_store_id', 'Not set' ) ); ?></code></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Webhook Secret', 'storedash' ); ?></strong></td>
									<td>
										<?php if ( $webhook_secret ) : ?>
											<code id="storedash-wp-webhook-secret-masked"><?php echo esc_html( $masked_secret ); ?></code>
											<?php // The full secret is intentionally never emitted into the page — use the secret hash below to verify a match against the Storedash dashboard. ?>
										<?php else : ?>
											<span class="storedash-wp-text-warning"><?php esc_html_e( 'Not configured — push config from Storedash dashboard', 'storedash' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Secret Hash (first 8)', 'storedash' ); ?></strong></td>
									<td>
										<?php if ( $webhook_secret ) : ?>
											<code><?php echo esc_html( substr( hash( 'sha256', $webhook_secret ), 0, 8 ) ); ?></code>
											<em style="color: #666; margin-left: 8px;"><?php esc_html_e( 'Compare with Storedash DB to verify match', 'storedash' ); ?></em>
										<?php else : ?>
											<span class="storedash-wp-text-warning">—</span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Cart Webhook URL', 'storedash' ); ?></strong></td>
									<td><code style="word-break: break-all;"><?php echo esc_html( $cart_webhook_url ); ?></code></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Waitlist Webhook URL', 'storedash' ); ?></strong></td>
									<td><code style="word-break: break-all;"><?php echo esc_html( get_option( 'storedash_waitlist_webhook_url', 'https://webhooks.storedash.io/q13udwod7lphmi' ) ); ?></code></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Rewards Credit Webhook URL', 'storedash' ); ?></strong></td>
									<td><code style="word-break: break-all;"><?php echo esc_html( get_option( 'storedash_credit_webhook_url', 'https://webhooks.storedash.io/qruffal2hfp3sy' ) ); ?></code></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Standard Webhook URL', 'storedash' ); ?></strong></td>
									<td><code style="word-break: break-all;"><?php echo esc_html( get_option( 'woodash_webhook_url', 'Not configured' ) ); ?></code></td>
								</tr>
							</tbody>
						</table>
					</div>
					<div style="margin-top: 16px;">
						<?php
						StoreDash_Admin_Components::button(
							array(
								'text'    => __( 'Send Test Webhook', 'storedash' ),
								'variant' => 'secondary',
								'id'      => 'storedash-wp-diag-test-webhook',
							)
						);
						?>
						<span id="storedash-wp-diag-test-webhook-result" style="margin-left: 12px;"></span>
					</div>
				</div>
			</div>

			<!-- REST API Endpoints Health Card -->
			<div class="storedash-wp-card storedash-wp-card--full storedash-wp-diagnostics-section" data-section="rest-api-endpoints">
				<div class="storedash-wp-card__header">
					<h3 class="storedash-wp-card__header-title"><?php esc_html_e( 'REST API Endpoints Health', 'storedash' ); ?></h3>
					<button type="button" class="storedash-wp-section-toggle" aria-expanded="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="18 15 12 9 6 15"></polyline>
						</svg>
					</button>
				</div>
				<div class="storedash-wp-card__body storedash-wp-section-content">
					<p style="margin-bottom: 16px;">
						<?php esc_html_e( 'Check the health and availability of all Storedash REST API endpoints. These endpoints power the integration between your WooCommerce store and the Storedash dashboard.', 'storedash' ); ?>
					</p>
					<p style="margin-bottom: 16px;">
						<?php
						StoreDash_Admin_Components::button(
							array(
								'text'    => __( 'Check All Endpoints', 'storedash' ),
								'variant' => 'primary',
								'id'      => 'storedash-wp-check-endpoints',
							)
						);
						?>
						<span id="storedash-wp-endpoints-loading" style="display: none; margin-left: 10px;">
							<span class="spinner is-active" style="float: none; margin: 0;"></span>
							<?php esc_html_e( 'Checking endpoints...', 'storedash' ); ?>
						</span>
					</p>
					<div id="storedash-wp-endpoints-results">
						<?php $this->render_endpoints_table(); ?>
					</div>
				</div>
			</div>

			<!-- Performance Metrics Card -->
			<div class="storedash-wp-card storedash-wp-card--full storedash-wp-diagnostics-section" data-section="performance-metrics">
				<div class="storedash-wp-card__header">
					<h3 class="storedash-wp-card__header-title"><?php esc_html_e( 'Performance Metrics', 'storedash' ); ?></h3>
					<button type="button" class="storedash-wp-section-toggle" aria-expanded="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="18 15 12 9 6 15"></polyline>
						</svg>
					</button>
				</div>
				<div class="storedash-wp-card__body storedash-wp-section-content">
					<div class="storedash-wp-table-wrapper">
						<table class="storedash-wp-table">
							<tbody>
								<tr>
									<td><strong><?php esc_html_e( 'Memory Usage', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['performance_metrics']['memory_usage'] ); ?> (<?php echo esc_html( $system_info['performance_metrics']['memory_usage_percent'] ); ?>%)</td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Memory Peak', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['performance_metrics']['memory_peak'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Memory Limit', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['performance_metrics']['memory_limit'] ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Query Count', 'storedash' ); ?></strong></td>
									<td><?php echo esc_html( $system_info['performance_metrics']['query_count'] ); ?></td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- File System Permissions Card -->
			<div class="storedash-wp-card storedash-wp-card--full storedash-wp-diagnostics-section" data-section="file-permissions">
				<div class="storedash-wp-card__header">
					<h3 class="storedash-wp-card__header-title"><?php esc_html_e( 'File System Permissions', 'storedash' ); ?></h3>
					<button type="button" class="storedash-wp-section-toggle" aria-expanded="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="18 15 12 9 6 15"></polyline>
						</svg>
					</button>
				</div>
				<div class="storedash-wp-card__body storedash-wp-section-content">
					<div class="storedash-wp-table-wrapper">
						<table class="storedash-wp-table">
							<tbody>
								<tr>
									<td><strong><?php esc_html_e( 'Uploads Directory Writable', 'storedash' ); ?></strong></td>
									<td><?php echo $system_info['file_permissions']['uploads_writable'] ? '<span class="storedash-wp-text-success">✓ ' . esc_html__( 'Yes', 'storedash' ) . '</span>' : '<span class="storedash-wp-text-warning">✗ ' . esc_html__( 'No', 'storedash' ) . '</span>'; ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Plugins Directory Writable', 'storedash' ); ?></strong></td>
									<td><?php echo $system_info['file_permissions']['plugins_writable'] ? '<span class="storedash-wp-text-success">✓ ' . esc_html__( 'Yes', 'storedash' ) . '</span>' : '<span class="storedash-wp-text-warning">✗ ' . esc_html__( 'No', 'storedash' ) . '</span>'; ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Themes Directory Writable', 'storedash' ); ?></strong></td>
									<td><?php echo $system_info['file_permissions']['themes_writable'] ? '<span class="storedash-wp-text-success">✓ ' . esc_html__( 'Yes', 'storedash' ) . '</span>' : '<span class="storedash-wp-text-warning">✗ ' . esc_html__( 'No', 'storedash' ) . '</span>'; ?></td>
								</tr>
								<tr>
									<td><strong>wp-config.php</strong></td>
									<td>
										<?php echo $system_info['file_permissions']['wp_config']['exists'] ? '<span class="storedash-wp-text-success">✓ ' . esc_html__( 'Exists', 'storedash' ) . '</span>' : '<span class="storedash-wp-text-warning">✗ ' . esc_html__( 'Not found', 'storedash' ) . '</span>'; ?>
										<?php if ( $system_info['file_permissions']['wp_config']['exists'] ) : ?>
											<?php echo $system_info['file_permissions']['wp_config']['readable'] ? ' / <span class="storedash-wp-text-success">' . esc_html__( 'Readable', 'storedash' ) . '</span>' : ' / <span class="storedash-wp-text-warning">' . esc_html__( 'Not readable', 'storedash' ) . '</span>'; ?>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<td><strong>.htaccess</strong></td>
									<td>
										<?php echo $system_info['file_permissions']['htaccess']['exists'] ? '<span class="storedash-wp-text-success">✓ ' . esc_html__( 'Exists', 'storedash' ) . '</span>' : '<span class="storedash-wp-text-warning">✗ ' . esc_html__( 'Not found', 'storedash' ) . '</span>'; ?>
										<?php if ( $system_info['file_permissions']['htaccess']['exists'] ) : ?>
											<?php echo $system_info['file_permissions']['htaccess']['writable'] ? ' / <span class="storedash-wp-text-success">' . esc_html__( 'Writable', 'storedash' ) . '</span>' : ' / <span class="storedash-wp-text-warning">' . esc_html__( 'Not writable', 'storedash' ) . '</span>'; ?>
										<?php endif; ?>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- Connectivity Test Card -->
			<div class="storedash-wp-card storedash-wp-card--full storedash-wp-diagnostics-section" data-section="connectivity-test">
				<div class="storedash-wp-card__header">
					<h3 class="storedash-wp-card__header-title"><?php esc_html_e( 'Connectivity Test', 'storedash' ); ?></h3>
					<button type="button" class="storedash-wp-section-toggle" aria-expanded="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="18 15 12 9 6 15"></polyline>
						</svg>
					</button>
				</div>
				<div class="storedash-wp-card__body storedash-wp-section-content">
					<p>
						<?php esc_html_e( 'This test checks if your WordPress site can successfully connect to Storedash\'s authentication servers. If you\'re experiencing issues during the onboarding process, run this test to identify the problem.', 'storedash' ); ?>
					</p>
					<p style="margin-top: 16px;">
						<?php
						StoreDash_Admin_Components::button(
							array(
								'text'    => __( 'Run Connectivity Test', 'storedash' ),
								'variant' => 'primary',
								'id'      => 'storedash-wp-run-diagnostics',
							)
						);
						?>
						<span id="storedash-wp-test-loading" style="display: none; margin-left: 10px;">
							<span class="spinner is-active" style="float: none; margin: 0;"></span>
							<?php esc_html_e( 'Testing...', 'storedash' ); ?>
						</span>
					</p>
					<div id="storedash-wp-test-results" style="display: none; margin-top: 20px;">
						<h4><?php esc_html_e( 'Test Results', 'storedash' ); ?></h4>
						<div id="storedash-wp-test-output" style="background: #f6f7f7; border: 1px solid #ddd; padding: 15px; border-radius: 4px; overflow-x: auto;"></div>
					</div>
				</div>
			</div>

			<!-- Common Issues Card -->
			<div class="storedash-wp-card storedash-wp-card--full storedash-wp-diagnostics-section" data-section="common-issues">
				<div class="storedash-wp-card__header">
					<h3 class="storedash-wp-card__header-title"><?php esc_html_e( 'Common Issues', 'storedash' ); ?></h3>
					<button type="button" class="storedash-wp-section-toggle" aria-expanded="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="18 15 12 9 6 15"></polyline>
						</svg>
					</button>
				</div>
				<div class="storedash-wp-card__body storedash-wp-section-content">
					<ul style="list-style: disc; margin-left: 20px;">
						<li><strong><?php esc_html_e( 'SSL Certificate Errors:', 'storedash' ); ?></strong> <?php esc_html_e( 'Your hosting provider may have outdated SSL certificates. Contact them to update.', 'storedash' ); ?></li>
						<li><strong><?php esc_html_e( 'Firewall Blocking:', 'storedash' ); ?></strong> <?php esc_html_e( 'Some hosts block outbound HTTPS connections. Ask your host to whitelist app.storedash.io', 'storedash' ); ?></li>
						<li><strong><?php esc_html_e( 'DNS Issues:', 'storedash' ); ?></strong> <?php esc_html_e( 'If DNS resolution fails, your server may not be able to resolve app.storedash.io', 'storedash' ); ?></li>
						<li><strong><?php esc_html_e( 'Timeout:', 'storedash' ); ?></strong> <?php esc_html_e( 'If requests timeout, your server may have slow network or be behind a restrictive firewall', 'storedash' ); ?></li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}


	// ========== AJAX HANDLERS ==========

	/**
	 * AJAX handler for saving cart settings
	 */
	public function ajax_save_cart_settings() {
		check_ajax_referer( 'storedash-wp-admin', 'nonce', false ) || wp_send_json_error( array( 'message' => __( 'Security check failed', 'storedash' ) ) );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'storedash' ) ) );
			return;
		}

		// Cart tracking, marketing opt-in, abandonment time and retention days are
		// provisioned from the Storedash dashboard (storedash/v1/carts/settings); the
		// store ID and webhook signing secret via storedash/v1/store-config. Only the
		// local Clean Uninstall flag is saved from this screen, so a local save can
		// never wipe the dashboard-managed values.
		$clean_uninstall = isset( $_POST['clean_uninstall'] ) ? (bool) $_POST['clean_uninstall'] : false;
		update_option( 'storedash_clean_uninstall', $clean_uninstall );

		wp_send_json_success( array( 'message' => __( 'Settings saved successfully!', 'storedash' ) ) );
	}

	/**
	 * AJAX handler for testing webhook connectivity.
	 *
	 * Sends a signed cart.test event to the cart webhook URL so the Go sync
	 * service can verify the HMAC signature. A successful 200 means the secret
	 * matches and the endpoint is reachable.
	 */
	public function ajax_test_webhook() {
		check_ajax_referer( 'storedash-wp-admin', 'nonce', false ) || wp_send_json_error( array( 'message' => __( 'Security check failed', 'storedash' ) ) );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'storedash' ) ) );
			return;
		}

		$webhook_url    = get_option( 'woodash_cart_webhook_url', 'https://webhooks.storedash.io/yvrrywi5uld4jq' );
		$webhook_secret = get_option( 'woodash_webhook_secret', '' );
		$store_id       = get_option( 'woodash_store_id', '' );

		if ( empty( $webhook_secret ) ) {
			wp_send_json_error( array( 'message' => __( 'Webhook secret not configured — push config from Storedash first', 'storedash' ) ) );
			return;
		}

		$payload = wp_json_encode(
			array(
				'event'     => 'cart.test',
				'store_id'  => (int) $store_id,
				'test'      => true,
				'timestamp' => gmdate( 'c' ),
				'source'    => 'storedash-wp-diagnostics',
			)
		);

		$signature = hash_hmac( 'sha256', $payload, $webhook_secret );

		$response = wp_remote_post(
			$webhook_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'        => 'application/json',
					'X-WooDash-Signature' => $signature,
				),
				'body'    => $payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					/* translators: %s: error message */
					'message'     => sprintf( __( 'Connection failed: %s', 'storedash' ), $response->get_error_message() ),
					'status_code' => 0,
				)
			);
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 200 && $status_code < 300 ) {
			wp_send_json_success(
				array(
					'message'     => __( 'Webhook test successful — signature verified', 'storedash' ),
					'status_code' => $status_code,
				)
			);
		} elseif ( 401 === $status_code || 403 === $status_code ) {
			wp_send_json_error(
				array(
					'message'     => __( 'Signature mismatch — webhook secret does not match Storedash. Re-push config from the dashboard.', 'storedash' ),
					'status_code' => $status_code,
				)
			);
		} else {
			wp_send_json_error(
				array(
					/* translators: %d: HTTP status code */
					'message'     => sprintf( __( 'Webhook returned HTTP %d', 'storedash' ), $status_code ),
					'status_code' => $status_code,
				)
			);
		}
	}

	/**
	 * AJAX handler for running diagnostics
	 */
	public function ajax_run_diagnostics() {
		check_ajax_referer( 'storedash-wp-admin', 'nonce', false ) || wp_send_json_error( array( 'message' => __( 'Security check failed', 'storedash' ) ) );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'storedash' ) ) );
			return;
		}

		// Run the connectivity test
		$callback_url = 'https://app.storedash.io/api/woo-auth/callback';
		$health_url   = 'https://app.storedash.io/api/woo-auth/health';

		$results = array(
			'timestamp' => current_time( 'mysql' ),
			'tests'     => array(),
		);

		// Test 1: Health endpoint GET request
		$health_response = wp_remote_get(
			$health_url,
			array(
				'timeout'   => 15,
				'sslverify' => StoreDash_Helpers::get_ssl_verify(),
			)
		);

		if ( is_wp_error( $health_response ) ) {
			$results['tests']['health_check'] = array(
				'success'    => false,
				'error'      => $health_response->get_error_message(),
				'error_code' => $health_response->get_error_code(),
			);
		} else {
			$results['tests']['health_check'] = array(
				'success'     => true,
				'status_code' => wp_remote_retrieve_response_code( $health_response ),
				'body'        => json_decode( wp_remote_retrieve_body( $health_response ), true ),
			);
		}

		// Test 2: Callback endpoint GET request
		$callback_response = wp_remote_get(
			$callback_url,
			array(
				'timeout'   => 15,
				'sslverify' => StoreDash_Helpers::get_ssl_verify(),
			)
		);

		if ( is_wp_error( $callback_response ) ) {
			$results['tests']['callback_get'] = array(
				'success'    => false,
				'error'      => $callback_response->get_error_message(),
				'error_code' => $callback_response->get_error_code(),
			);
		} else {
			$results['tests']['callback_get'] = array(
				'success'     => true,
				'status_code' => wp_remote_retrieve_response_code( $callback_response ),
			);
		}

		// Test 3: Callback endpoint POST request (simulated)
		$post_response = wp_remote_post(
			$callback_url,
			array(
				'timeout'   => 15,
				'sslverify' => StoreDash_Helpers::get_ssl_verify(),
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'body'      => wp_json_encode( array( 'test' => true ) ),
			)
		);

		if ( is_wp_error( $post_response ) ) {
			$results['tests']['callback_post'] = array(
				'success'    => false,
				'error'      => $post_response->get_error_message(),
				'error_code' => $post_response->get_error_code(),
			);
		} else {
			$results['tests']['callback_post'] = array(
				'success'     => true,
				'status_code' => wp_remote_retrieve_response_code( $post_response ),
			);
		}

		// Test 4: DNS resolution
		$parsed_url = wp_parse_url( $callback_url );
		$host       = $parsed_url['host'];
		$dns_check  = gethostbyname( $host );

		$results['tests']['dns_resolution'] = array(
			'host'        => $host,
			'resolved_ip' => $dns_check,
			'success'     => $dns_check !== $host,
		);

		// Generate HTML output
		$html = $this->generate_test_results_html( $results );

		wp_send_json_success(
			array(
				'html'    => $html,
				'results' => $results,
			)
		);
	}

	/**
	 * Generate HTML for test results
	 */
	private function generate_test_results_html( $results ) {
		ob_start();
		?>
		<div class="test-result-section">
			<h4><?php esc_html_e( 'Test Run:', 'storedash' ); ?> <?php echo esc_html( $results['timestamp'] ); ?></h4>
		</div>

		<div class="test-result-section">
			<h4>1. <?php esc_html_e( 'Health Check', 'storedash' ); ?> (GET <?php echo esc_html( 'https://app.storedash.io/api/woo-auth/health' ); ?>)</h4>
			<?php if ( $results['tests']['health_check']['success'] ) : ?>
				<div class="test-result-item success">
					<strong class="test-success">✓ <?php esc_html_e( 'SUCCESS', 'storedash' ); ?></strong><br>
					<?php esc_html_e( 'Status Code:', 'storedash' ); ?> <?php echo esc_html( $results['tests']['health_check']['status_code'] ); ?><br>
					<?php esc_html_e( 'Response:', 'storedash' ); ?> <?php echo esc_html( $results['tests']['health_check']['body']['message'] ?? 'OK' ); ?>
				</div>
			<?php else : ?>
				<div class="test-result-item error">
					<strong class="test-error">✗ <?php esc_html_e( 'FAILED', 'storedash' ); ?></strong><br>
					<?php esc_html_e( 'Error:', 'storedash' ); ?> <?php echo esc_html( $results['tests']['health_check']['error'] ); ?><br>
					<?php esc_html_e( 'Error Code:', 'storedash' ); ?> <?php echo esc_html( $results['tests']['health_check']['error_code'] ); ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="test-result-section">
			<h4>2. <?php esc_html_e( 'Callback Endpoint GET Test', 'storedash' ); ?></h4>
			<?php if ( $results['tests']['callback_get']['success'] ) : ?>
				<div class="test-result-item success">
					<strong class="test-success">✓ <?php esc_html_e( 'SUCCESS', 'storedash' ); ?></strong><br>
					<?php esc_html_e( 'Status Code:', 'storedash' ); ?> <?php echo esc_html( $results['tests']['callback_get']['status_code'] ); ?>
				</div>
			<?php else : ?>
				<div class="test-result-item error">
					<strong class="test-error">✗ <?php esc_html_e( 'FAILED', 'storedash' ); ?></strong><br>
					<?php esc_html_e( 'Error:', 'storedash' ); ?> <?php echo esc_html( $results['tests']['callback_get']['error'] ); ?><br>
					<?php esc_html_e( 'Error Code:', 'storedash' ); ?> <?php echo esc_html( $results['tests']['callback_get']['error_code'] ); ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="test-result-section">
			<h4>3. <?php esc_html_e( 'Callback Endpoint POST Test', 'storedash' ); ?></h4>
			<?php if ( $results['tests']['callback_post']['success'] ) : ?>
				<div class="test-result-item success">
					<strong class="test-success">✓ <?php esc_html_e( 'SUCCESS', 'storedash' ); ?></strong><br>
					<?php esc_html_e( 'Status Code:', 'storedash' ); ?> <?php echo esc_html( $results['tests']['callback_post']['status_code'] ); ?><br>
					<em><?php esc_html_e( 'This is the test that matters most - if this passes, WooCommerce OAuth should work!', 'storedash' ); ?></em>
				</div>
			<?php else : ?>
				<div class="test-result-item error">
					<strong class="test-error">✗ <?php esc_html_e( 'FAILED', 'storedash' ); ?></strong><br>
					<?php esc_html_e( 'Error:', 'storedash' ); ?> <?php echo esc_html( $results['tests']['callback_post']['error'] ); ?><br>
					<?php esc_html_e( 'Error Code:', 'storedash' ); ?> <?php echo esc_html( $results['tests']['callback_post']['error_code'] ); ?><br>
					<strong>⚠️ <?php esc_html_e( 'This is the issue preventing WooCommerce OAuth from working!', 'storedash' ); ?></strong>
				</div>
			<?php endif; ?>
		</div>

		<div class="test-result-section">
			<h4>4. <?php esc_html_e( 'DNS Resolution', 'storedash' ); ?></h4>
			<?php if ( $results['tests']['dns_resolution']['success'] ) : ?>
				<div class="test-result-item success">
					<strong class="test-success">✓ <?php esc_html_e( 'SUCCESS', 'storedash' ); ?></strong><br>
					<?php esc_html_e( 'Host:', 'storedash' ); ?> <?php echo esc_html( $results['tests']['dns_resolution']['host'] ); ?><br>
					<?php esc_html_e( 'Resolved IP:', 'storedash' ); ?> <?php echo esc_html( $results['tests']['dns_resolution']['resolved_ip'] ); ?>
				</div>
			<?php else : ?>
				<div class="test-result-item error">
					<strong class="test-error">✗ <?php esc_html_e( 'FAILED', 'storedash' ); ?></strong><br>
					<?php esc_html_e( 'Host:', 'storedash' ); ?> <?php echo esc_html( $results['tests']['dns_resolution']['host'] ); ?><br>
					<?php esc_html_e( 'Could not resolve domain name', 'storedash' ); ?>
				</div>
			<?php endif; ?>
		</div>

		<?php
		// Overall assessment
		$all_passed = $results['tests']['health_check']['success'] &&
					$results['tests']['callback_get']['success'] &&
					$results['tests']['callback_post']['success'] &&
					$results['tests']['dns_resolution']['success'];
		?>

		<div class="test-result-section">
			<h4><?php esc_html_e( 'Overall Assessment', 'storedash' ); ?></h4>
			<?php if ( $all_passed ) : ?>
				<div class="test-result-item success">
					<strong class="test-success">✓ <?php esc_html_e( 'ALL TESTS PASSED', 'storedash' ); ?></strong><br>
					<?php esc_html_e( 'Your WordPress site can successfully connect to Storedash. WooCommerce OAuth should work properly.', 'storedash' ); ?>
				</div>
			<?php else : ?>
				<div class="test-result-item error">
					<strong class="test-error">✗ <?php esc_html_e( 'SOME TESTS FAILED', 'storedash' ); ?></strong><br>
					<?php esc_html_e( 'There are connectivity issues preventing WooCommerce OAuth from working.', 'storedash' ); ?><br><br>
					<strong><?php esc_html_e( 'Next Steps:', 'storedash' ); ?></strong>
					<ol style="margin-left: 20px;">
						<li><?php esc_html_e( 'Contact your hosting provider', 'storedash' ); ?></li>
						<li><?php esc_html_e( 'Share these test results with them', 'storedash' ); ?></li>
						<li><?php esc_html_e( 'Ask them to allow outbound HTTPS connections to app.storedash.io', 'storedash' ); ?></li>
						<li><?php esc_html_e( 'Ask them to update SSL/TLS certificates if needed', 'storedash' ); ?></li>
					</ol>
				</div>
			<?php endif; ?>
		</div>

		<style>
			.test-result-section {
				margin-bottom: 15px;
				padding-bottom: 15px;
				border-bottom: 1px solid #ddd;
			}
			.test-result-section:last-child {
				border-bottom: none;
				margin-bottom: 0;
				padding-bottom: 0;
			}
			.test-result-item {
				margin: 5px 0;
				padding: 8px;
				background: white;
				border-left: 3px solid #ddd;
			}
			.test-result-item.success {
				border-left-color: #46b450;
			}
			.test-result-item.error {
				border-left-color: #dc3232;
			}
			.test-success {
				color: #46b450;
			}
			.test-error {
				color: #dc3232;
			}
		</style>

		<?php
		return ob_get_clean();
	}


	// ========== REST API ENDPOINTS METHODS ==========

	/**
	 * Get all StoreDash REST API endpoints
	 *
	 * @return array
	 */
	private function get_storedash_endpoints() {
		$endpoints = array(
			// Core Endpoints
			array(
				'group'         => __( 'Core', 'storedash' ),
				'name'          => 'Ping',
				'route'         => '/storedash/v1/ping',
				'method'        => 'GET',
				'auth_required' => false,
				'description'   => __( 'Public endpoint for plugin verification during onboarding', 'storedash' ),
			),
			array(
				'group'         => __( 'Core', 'storedash' ),
				'name'          => 'Status',
				'route'         => '/storedash/v1/status',
				'method'        => 'GET',
				'auth_required' => true,
				'description'   => __( 'Full plugin status with version info', 'storedash' ),
			),
			array(
				'group'         => __( 'Core', 'storedash' ),
				'name'          => 'Verify',
				'route'         => '/storedash/v1/verify',
				'method'        => 'GET',
				'auth_required' => true,
				'description'   => __( 'Plugin verification with webhook status', 'storedash' ),
			),

			// Products Endpoints
			array(
				'group'         => __( 'Products', 'storedash' ),
				'name'          => 'Products List',
				'route'         => '/storedash/v1/products',
				'method'        => 'GET',
				'auth_required' => true,
				'description'   => __( 'List and manage products', 'storedash' ),
			),

			// Discounts Endpoints
			array(
				'group'         => __( 'Discounts', 'storedash' ),
				'name'          => 'Discounts Status',
				'route'         => '/storedash/v1/discounts/status',
				'method'        => 'GET',
				'auth_required' => true,
				'description'   => __( 'Storedash discount rules status', 'storedash' ),
			),
			array(
				'group'         => __( 'Discounts', 'storedash' ),
				'name'          => 'Discounts Sync',
				'route'         => '/storedash/v1/discounts/sync',
				'method'        => 'POST',
				'auth_required' => true,
				'description'   => __( 'Sync discount rules from Supabase', 'storedash' ),
			),

			// Subscriptions Endpoints (conditional)
			array(
				'group'         => __( 'Subscriptions', 'storedash' ),
				'name'          => 'Subscriptions',
				'route'         => '/storedash/v1/subscriptions',
				'method'        => 'GET',
				'auth_required' => true,
				'description'   => __( 'WooCommerce Subscriptions (if plugin active)', 'storedash' ),
				'conditional'   => ! class_exists( 'WC_Subscriptions' ),
			),

			// Media Endpoints
			array(
				'group'         => __( 'Media', 'storedash' ),
				'name'          => 'Media Gallery',
				'route'         => '/storedash/v1/media/gallery',
				'method'        => 'GET',
				'auth_required' => true,
				'description'   => __( 'Browse WordPress media library', 'storedash' ),
			),
			array(
				'group'         => __( 'Media', 'storedash' ),
				'name'          => 'Upload Media',
				'route'         => '/storedash/v1/media/upload',
				'method'        => 'POST',
				'auth_required' => true,
				'description'   => __( 'Upload files to media library', 'storedash' ),
			),
			array(
				'group'         => __( 'Media', 'storedash' ),
				'name'          => 'Upload from URL',
				'route'         => '/storedash/v1/media/upload-from-url',
				'method'        => 'POST',
				'auth_required' => true,
				'description'   => __( 'Import media from external URL', 'storedash' ),
			),

			// Emails Endpoints
			array(
				'group'         => __( 'Emails', 'storedash' ),
				'name'          => 'Email Templates',
				'route'         => '/storedash/v1/emails',
				'method'        => 'GET',
				'auth_required' => true,
				'description'   => __( 'WooCommerce email templates', 'storedash' ),
			),

			// Waitlist Endpoints
			array(
				'group'         => __( 'Waitlist', 'storedash' ),
				'name'          => 'Waitlist Pending',
				'route'         => '/storedash/v1/waitlist/pending',
				'method'        => 'GET',
				'auth_required' => true,
				'description'   => __( 'Pending waitlist entries', 'storedash' ),
			),
			array(
				'group'         => __( 'Waitlist', 'storedash' ),
				'name'          => 'Waitlist Stats',
				'route'         => '/storedash/v1/waitlist/stats',
				'method'        => 'GET',
				'auth_required' => true,
				'description'   => __( 'Waitlist statistics', 'storedash' ),
			),

			// Integrations Endpoints
			array(
				'group'         => __( 'Integrations', 'storedash' ),
				'name'          => 'Integrations List',
				'route'         => '/storedash/v1/integrations',
				'method'        => 'GET',
				'auth_required' => true,
				'description'   => __( 'Available third-party integrations', 'storedash' ),
			),

		);

		return $endpoints;
	}

	/**
	 * Check if a REST route is registered
	 *
	 * @param string $route The route to check
	 * @return bool
	 */
	private function is_route_registered( $route ) {
		// Ensure REST API is initialized (fires rest_api_init if not already done)
		if ( ! did_action( 'rest_api_init' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing the WordPress core rest_api_init action to enumerate routes for diagnostics.
			do_action( 'rest_api_init' );
		}

		$server = rest_get_server();
		$routes = $server->get_routes();

		// The diagnostics inventory below only tracks static StoreDash routes.
		// Using registered route regex patterns here can trigger warnings for
		// third-party route expressions, which can break the admin page.
		return isset( $routes[ $route ] );
	}

	/**
	 * Render the endpoints table
	 *
	 * @param array|null $check_results Optional results from endpoint health check
	 */
	private function render_endpoints_table( $check_results = null ) {
		$endpoints = $this->get_storedash_endpoints();
		$grouped   = array();

		// Group endpoints by category
		foreach ( $endpoints as $endpoint ) {
			$group = $endpoint['group'];
			if ( ! isset( $grouped[ $group ] ) ) {
				$grouped[ $group ] = array();
			}
			$grouped[ $group ][] = $endpoint;
		}
		?>
		<div class="storedash-wp-table-wrapper">
			<table class="storedash-wp-table storedash-wp-endpoints-table">
				<thead>
					<tr>
						<th style="width: 30%;"><?php esc_html_e( 'Endpoint', 'storedash' ); ?></th>
						<th style="width: 35%;"><?php esc_html_e( 'Route', 'storedash' ); ?></th>
						<th style="width: 10%;"><?php esc_html_e( 'Method', 'storedash' ); ?></th>
						<th style="width: 10%;"><?php esc_html_e( 'Auth', 'storedash' ); ?></th>
						<th style="width: 15%;"><?php esc_html_e( 'Status', 'storedash' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $grouped as $group_name => $group_endpoints ) : ?>
						<tr class="storedash-wp-endpoints-group-header">
							<td colspan="5">
								<strong><?php echo esc_html( $group_name ); ?></strong>
							</td>
						</tr>
						<?php foreach ( $group_endpoints as $endpoint ) : ?>
							<?php
							$is_conditional = ! empty( $endpoint['conditional'] );
							$is_registered  = $this->is_route_registered( $endpoint['route'] );
							$route_id       = sanitize_title( $endpoint['route'] );
							$status_class   = '';
							$status_text    = '';

							if ( $is_conditional ) {
								$status_class = 'storedash-wp-status-skipped';
								$status_text  = __( 'N/A', 'storedash' );
							} elseif ( $check_results && isset( $check_results[ $endpoint['route'] ] ) ) {
								$result = $check_results[ $endpoint['route'] ];
								if ( $result['healthy'] ) {
									$status_class = 'storedash-wp-status-healthy';
									$status_text  = __( 'Healthy', 'storedash' );
								} else {
									$status_class = 'storedash-wp-status-error';
									$status_text  = $result['error'] ?? __( 'Error', 'storedash' );
								}
							} elseif ( $is_registered ) {
								$status_class = 'storedash-wp-status-registered';
								$status_text  = __( 'Registered', 'storedash' );
							} else {
								$status_class = 'storedash-wp-status-missing';
								$status_text  = __( 'Not Found', 'storedash' );
							}
							?>
							<tr class="<?php echo $is_conditional ? 'storedash-wp-endpoint-conditional' : ''; ?>" data-route="<?php echo esc_attr( $endpoint['route'] ); ?>">
								<td>
									<strong><?php echo esc_html( $endpoint['name'] ); ?></strong>
									<br><small style="color: #6b7280;"><?php echo esc_html( $endpoint['description'] ); ?></small>
								</td>
								<td><code style="font-size: 12px;"><?php echo esc_html( $endpoint['route'] ); ?></code></td>
								<td>
									<span class="storedash-wp-badge storedash-wp-badge-<?php echo esc_attr( strtolower( $endpoint['method'] ) ); ?>">
										<?php echo esc_html( $endpoint['method'] ); ?>
									</span>
								</td>
								<td>
									<?php if ( $endpoint['auth_required'] ) : ?>
										<span class="storedash-wp-text-warning">🔒</span>
									<?php else : ?>
										<span class="storedash-wp-text-success">🌐</span>
									<?php endif; ?>
								</td>
								<td>
									<span class="storedash-wp-endpoint-status <?php echo esc_attr( $status_class ); ?>" id="status-<?php echo esc_attr( $route_id ); ?>">
										<?php echo esc_html( $status_text ); ?>
									</span>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p style="margin-top: 12px; font-size: 13px; color: #6b7280;">
			<strong><?php esc_html_e( 'Legend:', 'storedash' ); ?></strong>
			🔒 = <?php esc_html_e( 'Requires WooCommerce authentication', 'storedash' ); ?> |
			🌐 = <?php esc_html_e( 'Public (no auth required)', 'storedash' ); ?>
		</p>
		<?php
	}

	/**
	 * AJAX handler for checking endpoint health
	 */
	public function ajax_check_endpoints() {
		check_ajax_referer( 'storedash-wp-admin', 'nonce', false ) || wp_send_json_error( array( 'message' => __( 'Security check failed', 'storedash' ) ) );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'storedash' ) ) );
			return;
		}

		$endpoints = $this->get_storedash_endpoints();
		$results   = array();
		$site_url  = get_rest_url();

		foreach ( $endpoints as $endpoint ) {
			// Skip conditional endpoints that aren't available
			if ( ! empty( $endpoint['conditional'] ) ) {
				$results[ $endpoint['route'] ] = array(
					'healthy' => false,
					'skipped' => true,
					'error'   => __( 'Plugin not active', 'storedash' ),
				);
				continue;
			}

			// Check if route is registered
			if ( ! $this->is_route_registered( $endpoint['route'] ) ) {
				$results[ $endpoint['route'] ] = array(
					'healthy'    => false,
					'registered' => false,
					'error'      => __( 'Route not registered', 'storedash' ),
				);
				continue;
			}

			// For endpoints requiring auth, we only check if they're registered
			// We don't make actual requests since that would require authentication
			if ( $endpoint['auth_required'] ) {
				$results[ $endpoint['route'] ] = array(
					'healthy'       => true,
					'registered'    => true,
					'auth_required' => true,
					'note'          => __( 'Registered (auth required to test)', 'storedash' ),
				);
				continue;
			}

			// For public endpoints, actually test them
			$url      = $site_url . ltrim( $endpoint['route'], '/' );
			$response = wp_remote_get(
				$url,
				array(
					'timeout'   => 10,
					'sslverify' => StoreDash_Helpers::get_ssl_verify(),
				)
			);

			if ( is_wp_error( $response ) ) {
				$results[ $endpoint['route'] ] = array(
					'healthy' => false,
					'error'   => $response->get_error_message(),
				);
			} else {
				$status_code                   = wp_remote_retrieve_response_code( $response );
				$results[ $endpoint['route'] ] = array(
					'healthy'     => $status_code >= 200 && $status_code < 300,
					'status_code' => $status_code,
					/* translators: %d: HTTP status code */
					'error'       => $status_code >= 400 ? sprintf( __( 'HTTP %d', 'storedash' ), $status_code ) : null,
				);
			}
		}

		// Generate updated HTML
		ob_start();
		$this->render_endpoints_table( $results );
		$html = ob_get_clean();

		// Count healthy vs unhealthy
		$healthy_count = 0;
		$total_count   = 0;
		foreach ( $results as $route => $result ) {
			if ( empty( $result['skipped'] ) ) {
				++$total_count;
				if ( ! empty( $result['healthy'] ) ) {
					++$healthy_count;
				}
			}
		}

		wp_send_json_success(
			array(
				'html'    => $html,
				'results' => $results,
				'summary' => array(
					'total'     => $total_count,
					'healthy'   => $healthy_count,
					'unhealthy' => $total_count - $healthy_count,
				),
			)
		);
	}

	// ========== SYSTEM INFO HELPER METHODS ==========

	/**
	 * Get all system information
	 *
	 * @return array
	 */
	private function get_system_info() {
		return array(
			'system_overview'     => $this->get_system_overview(),
			'php_environment'     => $this->get_php_info(),
			'server_environment'  => $this->get_server_info(),
			'database_info'       => $this->get_database_info(),
			'network_info'        => $this->get_network_info(),
			'woocommerce_info'    => $this->get_woocommerce_info(),
			'storedash_info'      => $this->get_storedash_info(),
			'performance_metrics' => $this->get_performance_metrics(),
			'file_permissions'    => $this->get_file_permissions(),
		);
	}

	/**
	 * Get system overview information
	 *
	 * @return array
	 */
	private function get_system_overview() {
		global $wpdb;

		$theme          = wp_get_theme();
		$active_plugins = get_option( 'active_plugins', array() );
		$plugins_list   = array();

		foreach ( $active_plugins as $plugin ) {
			$plugin_data    = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
			$plugins_list[] = array(
				'name'    => $plugin_data['Name'],
				'version' => $plugin_data['Version'],
				'path'    => $plugin,
			);
		}

		return array(
			'wordpress_version'     => get_bloginfo( 'version' ),
			'site_url'              => get_site_url(),
			'home_url'              => get_home_url(),
			'active_theme'          => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'author'  => $theme->get( 'Author' ),
			),
			'active_plugins_count'  => count( $active_plugins ),
			'active_plugins'        => $plugins_list,
			'is_multisite'          => is_multisite(),
			'language'              => get_locale(),
			'permalink_structure'   => get_option( 'permalink_structure', 'Plain' ),
			'wp_cache'              => defined( 'WP_CACHE' ) && WP_CACHE,
			'external_object_cache' => wp_using_ext_object_cache(),
		);
	}

	/**
	 * Get PHP environment information
	 *
	 * @return array
	 */
	private function get_php_info() {
		$memory_limit       = ini_get( 'memory_limit' );
		$memory_limit_bytes = $this->memory_size_to_bytes( $memory_limit );

		return array(
			'version'             => PHP_VERSION,
			'sapi'                => php_sapi_name(),
			'memory_limit'        => $memory_limit,
			'memory_limit_bytes'  => $memory_limit_bytes,
			'max_execution_time'  => ini_get( 'max_execution_time' ),
			'max_input_vars'      => ini_get( 'max_input_vars' ),
			'post_max_size'       => ini_get( 'post_max_size' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'error_reporting'     => ini_get( 'error_reporting' ),
			'display_errors'      => ini_get( 'display_errors' ),
			'log_errors'          => ini_get( 'log_errors' ),
			'error_log'           => ini_get( 'error_log' ),
			'disabled_functions'  => ini_get( 'disable_functions' ),
			'loaded_extensions'   => get_loaded_extensions(),
			'allow_url_fopen'     => ini_get( 'allow_url_fopen' ),
		);
	}

	/**
	 * Get server environment information
	 *
	 * @return array
	 */
	private function get_server_info() {
		global $wpdb;

		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown';
		$document_root   = isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : ABSPATH;

		$disk_free_space  = null;
		$disk_total_space = null;
		if ( function_exists( 'disk_free_space' ) && function_exists( 'disk_total_space' ) ) {
			try {
				$disk_free_space  = disk_free_space( $document_root );
				$disk_total_space = disk_total_space( $document_root );
			} catch ( Exception $e ) {
				// Disk space check failed
			}
		}

		return array(
			'server_software'  => $server_software,
			'server_os'        => PHP_OS,
			'server_ip'        => isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : 'Unknown',
			'server_timezone'  => date_default_timezone_get(),
			'server_time'      => current_time( 'mysql' ),
			'document_root'    => $document_root,
			'disk_free_space'  => $disk_free_space ? $this->format_bytes( $disk_free_space ) : 'Unknown',
			'disk_total_space' => $disk_total_space ? $this->format_bytes( $disk_total_space ) : 'Unknown',
			'server_protocol'  => isset( $_SERVER['SERVER_PROTOCOL'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) ) : 'Unknown',
		);
	}

	/**
	 * Get database information
	 *
	 * @return array
	 */
	private function get_database_info() {
		global $wpdb;

		$db_version = $wpdb->db_version();
		$db_name    = $wpdb->dbname;
		$db_charset = $wpdb->charset;
		$db_collate = $wpdb->collate;

		// Get database size
		$db_size = 0;
		$tables  = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		if ( $tables ) {
			foreach ( $tables as $table ) {
				$db_size += isset( $table['Data_length'] ) ? $table['Data_length'] : 0;
				$db_size += isset( $table['Index_length'] ) ? $table['Index_length'] : 0;
			}
		}

		// Check Storedash tables - all tables created by the plugin
		// Note: woodash_carts is legacy name, storedash_carts is new name
		$storedash_tables        = array(
			'woodash_carts'              => 'Abandoned Carts (legacy)',
			'storedash_carts'            => 'Abandoned Carts',
			'storedash_discounts'        => 'Discount Rules',
			'storedash_product_waitlist' => 'Product Waitlist',
		);
		$storedash_tables_status = array();
		foreach ( $storedash_tables as $table => $description ) {
			$full_table_name = $wpdb->prefix . $table;
			$exists          = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table_name ) ) === $full_table_name;
			$row_count       = 0;
			if ( $exists ) {
				$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_table_name}`" );
			}
			$storedash_tables_status[ $table ] = array(
				'exists'      => $exists,
				'description' => $description,
				'row_count'   => $row_count,
			);
		}

		return array(
			'version'          => $db_version,
			'name'             => $db_name,
			'charset'          => $db_charset,
			'collate'          => $db_collate,
			'size'             => $this->format_bytes( $db_size ),
			'size_bytes'       => $db_size,
			'table_count'      => count( $tables ),
			'storedash_tables' => $storedash_tables_status,
		);
	}

	/**
	 * Get network and connectivity information
	 *
	 * @return array
	 */
	private function get_network_info() {
		$server_ip = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : 'Unknown';

		// Don't fetch outbound IP on page load - it makes external HTTP requests
		// This will be loaded via AJAX if needed, or shown as "Click to check"
		$outbound_ip = 'Not checked (click to check)';

		$curl_info = array();
		if ( function_exists( 'curl_version' ) ) {
			$curl_version = curl_version();
			$curl_info    = array(
				'version'     => $curl_version['version'],
				'ssl_version' => isset( $curl_version['ssl_version'] ) ? $curl_version['ssl_version'] : 'Unknown',
				'features'    => $curl_version['features'],
			);
		}

		return array(
			'server_ip'       => $server_ip,
			'outbound_ip'     => $outbound_ip,
			'curl_enabled'    => function_exists( 'curl_version' ),
			'curl_info'       => $curl_info,
			'openssl_enabled' => extension_loaded( 'openssl' ),
			'openssl_version' => extension_loaded( 'openssl' ) ? OPENSSL_VERSION_TEXT : 'Not available',
			'allow_url_fopen' => ini_get( 'allow_url_fopen' ),
			'ssl_verify'      => function_exists( 'curl_version' ),
		);
	}

	/**
	 * Get WooCommerce information
	 *
	 * @return array
	 */
	private function get_woocommerce_info() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'installed' => false );
		}

		$wc            = WC();
		$currency      = get_woocommerce_currency();
		$base_location = wc_get_base_location();

		// Check WooCommerce pages
		$pages = array(
			'shop'      => wc_get_page_id( 'shop' ),
			'cart'      => wc_get_page_id( 'cart' ),
			'checkout'  => wc_get_page_id( 'checkout' ),
			'myaccount' => wc_get_page_id( 'myaccount' ),
		);

		$pages_status = array();
		foreach ( $pages as $page_name => $page_id ) {
			$pages_status[ $page_name ] = array(
				'id'        => $page_id,
				'exists'    => $page_id > 0 && get_post( $page_id ),
				'published' => $page_id > 0 && get_post_status( $page_id ) === 'publish',
			);
		}

		return array(
			'installed'        => true,
			'version'          => defined( 'WC_VERSION' ) ? WC_VERSION : 'Unknown',
			'database_version' => get_option( 'woocommerce_db_version', 'Unknown' ),
			'currency'         => $currency,
			'base_location'    => $base_location,
			'pages'            => $pages_status,
			'tax_enabled'      => wc_tax_enabled(),
		);
	}

	/**
	 * Get Storedash plugin information
	 *
	 * @return array
	 */
	private function get_storedash_info() {
		global $wpdb;

		$webhook_url           = get_option( 'woodash_cart_webhook_url', '' );
		$store_id              = get_option( 'woodash_store_id', '' );
		$cart_tracking_enabled = \StoreDash\Carts\Services\Cart_Tracking::cart_tracking_enabled();
		return array(
			'version'               => STOREDASH_VERSION,
			'cart_tracking_enabled' => $cart_tracking_enabled,
			'webhook_url'           => $webhook_url ? 'Configured' : 'Not configured',
			'store_id'              => $store_id ?: 'Not set',
		);
	}

	/**
	 * Get performance metrics
	 *
	 * @return array
	 */
	private function get_performance_metrics() {
		$memory_usage       = memory_get_usage( true );
		$memory_peak        = memory_get_peak_usage( true );
		$memory_limit       = ini_get( 'memory_limit' );
		$memory_limit_bytes = $this->memory_size_to_bytes( $memory_limit );

		return array(
			'memory_usage'         => $this->format_bytes( $memory_usage ),
			'memory_usage_bytes'   => $memory_usage,
			'memory_peak'          => $this->format_bytes( $memory_peak ),
			'memory_peak_bytes'    => $memory_peak,
			'memory_limit'         => $memory_limit,
			'memory_limit_bytes'   => $memory_limit_bytes,
			'memory_usage_percent' => $memory_limit_bytes > 0 ? round( ( $memory_usage / $memory_limit_bytes ) * 100, 2 ) : 0,
			'query_count'          => defined( 'SAVEQUERIES' ) && SAVEQUERIES ? count( $GLOBALS['wpdb']->queries ) : 'Not tracked',
		);
	}

	/**
	 * Get file system permissions
	 *
	 * @return array
	 */
	private function get_file_permissions() {
		$uploads_dir      = wp_upload_dir();
		$uploads_writable = wp_is_writable( $uploads_dir['basedir'] );
		$plugins_writable = wp_is_writable( WP_PLUGIN_DIR );
		$themes_writable  = wp_is_writable( get_theme_root() );

		$wp_config_path     = ABSPATH . 'wp-config.php';
		$wp_config_exists   = file_exists( $wp_config_path );
		$wp_config_readable = $wp_config_exists ? is_readable( $wp_config_path ) : false;

		$htaccess_path     = ABSPATH . '.htaccess';
		$htaccess_exists   = file_exists( $htaccess_path );
		$htaccess_writable = $htaccess_exists ? wp_is_writable( $htaccess_path ) : false;

		return array(
			'uploads_writable' => $uploads_writable,
			'plugins_writable' => $plugins_writable,
			'themes_writable'  => $themes_writable,
			'wp_config'        => array(
				'exists'   => $wp_config_exists,
				'readable' => $wp_config_readable,
			),
			'htaccess'         => array(
				'exists'   => $htaccess_exists,
				'writable' => $htaccess_writable,
			),
		);
	}

	/**
	 * Format bytes to human-readable format
	 *
	 * @param int $bytes
	 * @return string
	 */
	private function format_bytes( $bytes ) {
		if ( $bytes >= 1073741824 ) {
			return number_format( $bytes / 1073741824, 2 ) . ' GB';
		} elseif ( $bytes >= 1048576 ) {
			return number_format( $bytes / 1048576, 2 ) . ' MB';
		} elseif ( $bytes >= 1024 ) {
			return number_format( $bytes / 1024, 2 ) . ' KB';
		} else {
			return $bytes . ' bytes';
		}
	}

	/**
	 * Convert memory size string to bytes
	 *
	 * @param string $size
	 * @return int
	 */
	private function memory_size_to_bytes( $size ) {
		$size = trim( $size );
		$last = strtolower( $size[ strlen( $size ) - 1 ] );
		$size = (int) $size;

		switch ( $last ) {
			case 'g':
				$size *= 1024;
				// Fall through — g accumulates m and k multipliers.
			case 'm':
				$size *= 1024;
				// Fall through — m accumulates the k multiplier.
			case 'k':
				$size *= 1024;
		}

		return $size;
	}

	// ========== AJAX HANDLERS FOR SYSTEM INFO ==========

	/**
	 * AJAX handler to get system info as JSON
	 */
	public function ajax_get_system_info() {
		check_ajax_referer( 'storedash-wp-admin', 'nonce', false ) || wp_send_json_error( array( 'message' => __( 'Security check failed', 'storedash' ) ) );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'storedash' ) ) );
			return;
		}

		$system_info = $this->get_system_info();
		wp_send_json_success( array( 'data' => $system_info ) );
	}

	/**
	 * AJAX handler to get system info as formatted text for copying
	 */
	public function ajax_copy_system_info() {
		check_ajax_referer( 'storedash-wp-admin', 'nonce', false ) || wp_send_json_error( array( 'message' => __( 'Security check failed', 'storedash' ) ) );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'storedash' ) ) );
			return;
		}

		$system_info = $this->get_system_info();
		$text        = $this->format_system_info_text( $system_info );

		wp_send_json_success( array( 'text' => $text ) );
	}

	/**
	 * AJAX handler to download system info as text file
	 */
	public function ajax_download_system_info() {
		check_ajax_referer( 'storedash-wp-admin', 'nonce', false ) || wp_die( esc_html__( 'Security check failed', 'storedash' ) );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'storedash' ) );
		}

		$system_info = $this->get_system_info();
		$text        = $this->format_system_info_text( $system_info );

		$filename = 'storedash-system-info-' . gmdate( 'Y-m-d-His' ) . '.txt';

		header( 'Content-Type: text/plain' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $text ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text file download (Content-Type: text/plain, attachment); HTML escaping would corrupt the file. Admin-only (manage_woocommerce); contains no secrets (see get_storedash_info()).
		echo $text;
		exit;
	}

	/**
	 * AJAX handler to export system info as JSON file
	 */
	public function ajax_export_system_info() {
		check_ajax_referer( 'storedash-wp-admin', 'nonce', false ) || wp_die( esc_html__( 'Security check failed', 'storedash' ) );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'storedash' ) );
		}

		$system_info = $this->get_system_info();
		$json        = wp_json_encode( $system_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		$filename = 'storedash-system-info-' . gmdate( 'Y-m-d-His' ) . '.json';

		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file download (Content-Type: application/json, attachment) produced by wp_json_encode(); HTML escaping would corrupt the file. Admin-only (manage_woocommerce); contains no secrets (see get_storedash_info()).
		echo $json;
		exit;
	}

	/**
	 * Format system info as readable text
	 *
	 * @param array $info
	 * @return string
	 */
	private function format_system_info_text( $info ) {
		$text  = "=== Storedash WP System Information ===\n";
		$text .= 'Generated: ' . current_time( 'mysql' ) . "\n\n";

		// System Overview
		$text .= "--- SYSTEM OVERVIEW ---\n";
		$text .= 'WordPress Version: ' . $info['system_overview']['wordpress_version'] . "\n";
		$text .= 'Site URL: ' . $info['system_overview']['site_url'] . "\n";
		$text .= 'Home URL: ' . $info['system_overview']['home_url'] . "\n";
		$text .= 'Active Theme: ' . $info['system_overview']['active_theme']['name'] . ' ' . $info['system_overview']['active_theme']['version'] . "\n";
		$text .= 'Active Plugins: ' . $info['system_overview']['active_plugins_count'] . "\n";
		foreach ( $info['system_overview']['active_plugins'] as $plugin ) {
			$text .= '  - ' . $plugin['name'] . ' ' . $plugin['version'] . "\n";
		}
		$text .= 'Multisite: ' . ( $info['system_overview']['is_multisite'] ? 'Yes' : 'No' ) . "\n";
		$text .= 'Language: ' . $info['system_overview']['language'] . "\n";
		$text .= 'Permalink Structure: ' . $info['system_overview']['permalink_structure'] . "\n";
		$text .= 'WP_CACHE: ' . ( $info['system_overview']['wp_cache'] ? 'Yes' : 'No' ) . "\n";
		$text .= 'External Object Cache: ' . ( $info['system_overview']['external_object_cache'] ? 'Yes' : 'No' ) . "\n\n";

		// PHP Environment
		$text .= "--- PHP ENVIRONMENT ---\n";
		$text .= 'PHP Version: ' . $info['php_environment']['version'] . "\n";
		$text .= 'PHP SAPI: ' . $info['php_environment']['sapi'] . "\n";
		$text .= 'Memory Limit: ' . $info['php_environment']['memory_limit'] . "\n";
		$text .= 'Max Execution Time: ' . $info['php_environment']['max_execution_time'] . "\n";
		$text .= 'Max Input Vars: ' . $info['php_environment']['max_input_vars'] . "\n";
		$text .= 'Post Max Size: ' . $info['php_environment']['post_max_size'] . "\n";
		$text .= 'Upload Max Filesize: ' . $info['php_environment']['upload_max_filesize'] . "\n";
		$text .= 'Allow URL FOpen: ' . ( $info['php_environment']['allow_url_fopen'] ? 'Yes' : 'No' ) . "\n";
		$text .= 'Loaded Extensions: ' . count( $info['php_environment']['loaded_extensions'] ) . "\n";
		$text .= implode( ', ', array_slice( $info['php_environment']['loaded_extensions'], 0, 20 ) ) . "...\n\n";

		// Server Environment
		$text .= "--- SERVER ENVIRONMENT ---\n";
		$text .= 'Server Software: ' . $info['server_environment']['server_software'] . "\n";
		$text .= 'Server OS: ' . $info['server_environment']['server_os'] . "\n";
		$text .= 'Server IP: ' . $info['server_environment']['server_ip'] . "\n";
		$text .= 'Server Timezone: ' . $info['server_environment']['server_timezone'] . "\n";
		$text .= 'Document Root: ' . $info['server_environment']['document_root'] . "\n";
		$text .= 'Disk Free Space: ' . $info['server_environment']['disk_free_space'] . "\n";
		$text .= 'Disk Total Space: ' . $info['server_environment']['disk_total_space'] . "\n\n";

		// Database
		$text .= "--- DATABASE INFORMATION ---\n";
		$text .= 'Database Version: ' . $info['database_info']['version'] . "\n";
		$text .= 'Database Name: ' . $info['database_info']['name'] . "\n";
		$text .= 'Database Charset: ' . $info['database_info']['charset'] . "\n";
		$text .= 'Database Collate: ' . $info['database_info']['collate'] . "\n";
		$text .= 'Database Size: ' . $info['database_info']['size'] . "\n";
		$text .= 'Table Count: ' . $info['database_info']['table_count'] . "\n\n";

		// Network
		$text .= "--- NETWORK & CONNECTIVITY ---\n";
		$text .= 'Server IP: ' . $info['network_info']['server_ip'] . "\n";
		$text .= 'Outbound IP: ' . $info['network_info']['outbound_ip'] . " (not checked on page load to avoid slowdown)\n";
		$text .= 'cURL Enabled: ' . ( $info['network_info']['curl_enabled'] ? 'Yes' : 'No' ) . "\n";
		if ( $info['network_info']['curl_enabled'] ) {
			$text .= 'cURL Version: ' . $info['network_info']['curl_info']['version'] . "\n";
			$text .= 'SSL Version: ' . $info['network_info']['curl_info']['ssl_version'] . "\n";
		}
		$text .= 'OpenSSL Enabled: ' . ( $info['network_info']['openssl_enabled'] ? 'Yes' : 'No' ) . "\n";
		$text .= 'OpenSSL Version: ' . $info['network_info']['openssl_version'] . "\n\n";

		// WooCommerce
		if ( $info['woocommerce_info']['installed'] ) {
			$text .= "--- WOOCOMMERCE INFORMATION ---\n";
			$text .= 'WooCommerce Version: ' . $info['woocommerce_info']['version'] . "\n";
			$text .= 'Database Version: ' . $info['woocommerce_info']['database_version'] . "\n";
			$text .= 'Currency: ' . $info['woocommerce_info']['currency'] . "\n";
			$text .= 'Base Location: ' . $info['woocommerce_info']['base_location']['country'] . "\n";
			$text .= 'Tax Enabled: ' . ( $info['woocommerce_info']['tax_enabled'] ? 'Yes' : 'No' ) . "\n\n";
		}

		// Storedash
		$text .= "--- STOREDASH PLUGIN ---\n";
		$text .= 'Version: ' . $info['storedash_info']['version'] . "\n";
		$text .= 'Cart Tracking: ' . ( $info['storedash_info']['cart_tracking_enabled'] ? 'Enabled' : 'Disabled' ) . "\n";
		$text .= 'Webhook URL: ' . $info['storedash_info']['webhook_url'] . "\n";
		$text .= 'Store ID: ' . $info['storedash_info']['store_id'] . "\n";

		// Performance
		$text .= "--- PERFORMANCE METRICS ---\n";
		$text .= 'Memory Usage: ' . $info['performance_metrics']['memory_usage'] . "\n";
		$text .= 'Memory Peak: ' . $info['performance_metrics']['memory_peak'] . "\n";
		$text .= 'Memory Limit: ' . $info['performance_metrics']['memory_limit'] . "\n";
		$text .= 'Memory Usage: ' . $info['performance_metrics']['memory_usage_percent'] . "%\n";
		$text .= 'Query Count: ' . $info['performance_metrics']['query_count'] . "\n\n";

		// File Permissions
		$text .= "--- FILE SYSTEM PERMISSIONS ---\n";
		$text .= 'Uploads Writable: ' . ( $info['file_permissions']['uploads_writable'] ? 'Yes' : 'No' ) . "\n";
		$text .= 'Plugins Writable: ' . ( $info['file_permissions']['plugins_writable'] ? 'Yes' : 'No' ) . "\n";
		$text .= 'Themes Writable: ' . ( $info['file_permissions']['themes_writable'] ? 'Yes' : 'No' ) . "\n";
		$text .= 'wp-config.php Exists: ' . ( $info['file_permissions']['wp_config']['exists'] ? 'Yes' : 'No' ) . "\n";
		$text .= 'wp-config.php Readable: ' . ( $info['file_permissions']['wp_config']['readable'] ? 'Yes' : 'No' ) . "\n";
		$text .= '.htaccess Exists: ' . ( $info['file_permissions']['htaccess']['exists'] ? 'Yes' : 'No' ) . "\n";
		$text .= '.htaccess Writable: ' . ( $info['file_permissions']['htaccess']['writable'] ? 'Yes' : 'No' ) . "\n";

		return $text;
	}
}

