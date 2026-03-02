<?php
/**
 * Plugin Name: ConnectMe System
 * Description: Vendor directory + ConnectMe request workflow (token-based Yes/No), reminders, requester notifications, and vendor opt-in verification flow.
 * Version: 2.2.0
 * Author: Felix Frederick G. Cordero Jr.
 */

if ( ! defined('ABSPATH') ) exit;

final class ConnectMe_System {

	/* =============================
	 * Versioning
	 * ============================= */

	const VERSION        = '2.2.0';
	const DB_VERSION     = '1.0.0';
	const DB_VERSION_KEY = 'connectme_db_version';

	/* =============================
	 * Tables / Keys
	 * ============================= */

	// Greg wants the literal legacy vendor table name (not prefix-based).
	const VENDOR_TABLE       = 'wp_vendor_directory';

	// Requests table is prefix-based.
	const REQUESTS_TABLE_KEY = 'connectme_requests';

	/* =============================
	 * Shortcodes / Routes
	 * ============================= */

	const SHORTCODE_DIRECTORY = 'connectme_vendor_directory';

	// Request Yes/No responses
	const RESPOND_PATH = '/connectme/respond/';

	// Vendor opt-in verify / decline
	const OPTIN_VERIFY_PATH  = '/connectme/verify/';
	const OPTIN_DECLINE_PATH = '/connectme/decline/';

	/* =============================
	 * Behavior switches
	 * ============================= */

	// true = log emails/links to error_log (safe for sandbox)
	// false = actually send wp_mail
	const EMAIL_DRY_RUN = true;

	// Directory filter: set true ONLY after opt-in emails have been sent (otherwise directory may appear empty).
	const DIRECTORY_REQUIRE_OPTIN = false;

	// Opt-in token TTL (hours)
	const OPTIN_TOKEN_TTL_HOURS = 72;

	// Reminder schedule (days after initial request)
	// Stage 1 => +1 day, Stage 2 => +3 days, Stage 3 => +4 days (relative to created_at)
	private static $reminder_days = array(1, 3, 4);

	/* =============================
	 * AJAX
	 * ============================= */

	const AJAX_ACTION = 'connectme_create_request';
	const NONCE_KEY   = 'connectme_nonce';

	/* =============================
	 * Init / Activation
	 * ============================= */

	public static function init() {
		register_activation_hook(__FILE__, array(__CLASS__, 'activate'));

		add_action('admin_init', array(__CLASS__, 'maybe_upgrade_db'));

		add_shortcode(self::SHORTCODE_DIRECTORY, array(__CLASS__, 'render_directory'));

		add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));

		add_action('wp_ajax_' . self::AJAX_ACTION, array(__CLASS__, 'ajax_create_request'));
		add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, array(__CLASS__, 'ajax_create_request'));

		add_filter('query_vars', array(__CLASS__, 'query_vars'));
		add_action('template_redirect', array(__CLASS__, 'handle_routes'));

		// Reminder cron handler
		add_action('connectme_send_reminder', array(__CLASS__, 'cron_send_reminder'), 10, 2);
	}

	public static function activate() {
		self::maybe_create_requests_table();
		self::maybe_upgrade_db(true);
	}

	private static function requests_table() {
		global $wpdb;
		return $wpdb->prefix . self::REQUESTS_TABLE_KEY;
	}

	/* =============================
	 * DB: Requests Table
	 * ============================= */

	private static function maybe_create_requests_table() {
		global $wpdb;

		$table   = self::requests_table();
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			requester_user_id BIGINT UNSIGNED NOT NULL,
			vendor_id BIGINT UNSIGNED NOT NULL,
			vendor_email VARCHAR(190) NOT NULL,
			vendor_user_id BIGINT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			token CHAR(64) NOT NULL,
			created_at DATETIME NOT NULL,
			responded_at DATETIME NULL,
			response_ip VARCHAR(64) NULL,
			response_ua VARCHAR(255) NULL,
			reminder_stage TINYINT UNSIGNED NOT NULL DEFAULT 0,
			last_reminded_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY token_unique (token),
			KEY requester_vendor (requester_user_id, vendor_id),
			KEY vendor_email_idx (vendor_email),
			KEY status_idx (status),
			KEY reminder_stage_idx (reminder_stage)
		) {$charset};";

		dbDelta($sql);
	}

	/* =============================
	 * DB: Vendor Opt-in Columns (Step 1)
	 * ============================= */

	public static function maybe_upgrade_db($force = false) {
		if ( ! $force ) {
			if ( ! is_admin() ) return;
			if ( ! current_user_can('manage_options') ) return;
		}

		$installed = get_option(self::DB_VERSION_KEY, '0.0.0');
		if ( ! $force && version_compare($installed, self::DB_VERSION, '>=') ) {
			return;
		}

		global $wpdb;
		$vendor_table = self::VENDOR_TABLE;

		$exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $vendor_table));
		if ( $exists !== $vendor_table ) {
			error_log('[ConnectMe] Vendor table not found: ' . $vendor_table);
			return;
		}

		self::add_column_if_missing($vendor_table, 'connectme_opt_in',           "TINYINT(1) NULL DEFAULT NULL");
		self::add_column_if_missing($vendor_table, 'connectme_verified_at',      "DATETIME NULL DEFAULT NULL");
		self::add_column_if_missing($vendor_table, 'connectme_token_hash',       "VARCHAR(255) NULL DEFAULT NULL");
		self::add_column_if_missing($vendor_table, 'connectme_token_expires_at', "DATETIME NULL DEFAULT NULL");

		self::add_index_if_missing($vendor_table, 'idx_connectme_opt_in',     "(connectme_opt_in)");
		self::add_index_if_missing($vendor_table, 'idx_connectme_token_hash', "(connectme_token_hash)");

		update_option(self::DB_VERSION_KEY, self::DB_VERSION);
		error_log('[ConnectMe] DB upgrade complete. Version=' . self::DB_VERSION);
	}

	private static function add_column_if_missing($table, $column, $definition) {
		global $wpdb;

		$exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", $column));
		if ( $exists ) return;

		$wpdb->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
	}

	private static function add_index_if_missing($table, $index_name, $columns_sql) {
		global $wpdb;

		$indexes = $wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A);
		if ( is_array($indexes) ) {
			foreach ($indexes as $idx) {
				if ( ! empty($idx['Key_name']) && $idx['Key_name'] === $index_name ) {
					return;
				}
			}
		}

		$wpdb->query("ALTER TABLE `$table` ADD INDEX `$index_name` $columns_sql");
	}

	/* =============================
	 * Assets
	 * ============================= */

	public static function enqueue_assets() {
		$style_handle = 'connectme-style';
		wp_register_style($style_handle, false, array(), self::VERSION);
		wp_enqueue_style($style_handle);
		wp_add_inline_style($style_handle, self::css());

		$script_handle = 'connectme-script';
		wp_register_script($script_handle, false, array(), self::VERSION, true);
		wp_enqueue_script($script_handle);

		$data = array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce(self::NONCE_KEY),
		);

		wp_add_inline_script($script_handle, 'window.ConnectMe=' . wp_json_encode($data) . ';' . self::js());
	}

	/* =============================
	 * Directory (Shortcode)
	 * ============================= */

	public static function render_directory($atts = array()) {
		global $wpdb;

		$atts = shortcode_atts(array(
			'per_page' => 25,
		), $atts, self::SHORTCODE_DIRECTORY);

		$per_page = max(5, min(100, (int) $atts['per_page']));
		$table    = self::VENDOR_TABLE;

		$exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
		if ( $exists !== $table ) {
			return '<div class="cm-wrap"><div class="cm-empty"><strong>Vendor table not found:</strong> ' . esc_html($table) . '</div></div>';
		}

		$vendor_type = isset($_GET['vendor_type']) ? sanitize_text_field(wp_unslash($_GET['vendor_type'])) : '';
		$event_state = isset($_GET['event_state']) ? sanitize_text_field(wp_unslash($_GET['event_state'])) : '';
		$page        = isset($_GET['vd_page']) ? max(1, (int) $_GET['vd_page']) : 1;

		$offset = ($page - 1) * $per_page;

		$where  = array();
		$params = array();

		if ( self::DIRECTORY_REQUIRE_OPTIN ) {
			$where[] = "connectme_opt_in = 1";
		}

		if ( $vendor_type !== '' ) {
			$where[]  = "vendor_type = %s";
			$params[] = $vendor_type;
		}

		if ( $event_state !== '' ) {
			$where[]  = "event_state = %s";
			$params[] = $event_state;
		}

		$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		$total = $params ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params)) : (int) $wpdb->get_var($count_sql);
		$total_pages = max(1, (int) ceil($total / $per_page));

		$data_sql = "
			SELECT *
			FROM {$table}
			{$where_sql}
			ORDER BY vendor_type ASC, COALESCE(company_name,'') ASC
			LIMIT %d OFFSET %d
		";
		$data_params = array_merge($params, array($per_page, $offset));
		$rows = $wpdb->get_results($wpdb->prepare($data_sql, $data_params), ARRAY_A);

		$types  = $wpdb->get_col("SELECT DISTINCT vendor_type FROM {$table} WHERE vendor_type IS NOT NULL AND vendor_type <> '' ORDER BY vendor_type ASC");
		$states = $wpdb->get_col("SELECT DISTINCT event_state FROM {$table} WHERE event_state IS NOT NULL AND event_state <> '' ORDER BY event_state ASC");

		ob_start();
		?>
		<div class="cm-wrap">
			<div class="cm-head">
				<h3 class="cm-title">ConnectMe Vendor Directory</h3>
				<p class="cm-sub">Browse vendors. Use filters to narrow results.</p>
			</div>

			<?php echo self::render_filters($types, $states, $vendor_type, $event_state); ?>

			<div class="cm-list">
				<?php if ( empty($rows) ) : ?>
					<div class="cm-empty">No vendors found.</div>
				<?php else : ?>
					<?php foreach ($rows as $row) : ?>
						<?php echo self::render_vendor_card($row); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<?php echo self::render_pagination($page, $total_pages); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_filters($types, $states, $vendor_type, $event_state) {
		$base_url = remove_query_arg(array('vendor_type','event_state','vd_page'));

		ob_start();
		?>
		<form class="cm-filters" method="get" action="<?php echo esc_url($base_url); ?>">
			<?php
			foreach ($_GET as $k => $v) {
				if ( in_array($k, array('vendor_type','event_state','vd_page'), true) ) continue;
				if ( is_array($v) ) continue;
				echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr(sanitize_text_field(wp_unslash($v))) . '">';
			}
			?>

			<label>
				<span>Vendor Type</span>
				<select name="vendor_type">
					<option value="">All</option>
					<?php foreach ($types as $t) : ?>
						<option value="<?php echo esc_attr($t); ?>" <?php selected($vendor_type, $t); ?>>
							<?php echo esc_html(self::labelize($t)); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<label>
				<span>State</span>
				<select name="event_state">
					<option value="">All States</option>
					<?php foreach ($states as $s) : ?>
						<option value="<?php echo esc_attr($s); ?>" <?php selected($event_state, $s); ?>>
							<?php echo esc_html($s); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<button type="submit" class="cm-btn">Apply</button>
			<a class="cm-link" href="<?php echo esc_url($base_url); ?>">Reset</a>
		</form>
		<?php
		return ob_get_clean();
	}

	private static function render_vendor_card($row) {
		$vendor_id = isset($row['id']) ? (int) $row['id'] : 0;

		$company = isset($row['company_name']) ? $row['company_name'] : '(No company name)';
		$type    = isset($row['vendor_type']) ? $row['vendor_type'] : 'vendor';
		$state   = isset($row['event_state']) ? $row['event_state'] : '';

		$phone   = isset($row['phone']) ? $row['phone'] : '';
		$email   = isset($row['email']) ? $row['email'] : '';
		$website = isset($row['website']) ? $row['website'] : '';
		$contact = isset($row['contact_name']) ? $row['contact_name'] : '';

		ob_start();
		?>
		<div class="cm-card">
			<div class="cm-card-top">
				<div class="cm-main">
					<div class="cm-company"><?php echo esc_html($company); ?></div>
					<div class="cm-meta">
						<span class="cm-pill"><?php echo esc_html(self::labelize($type)); ?></span>
						<?php if ($state !== '') : ?>
							<span class="cm-muted"><?php echo esc_html($state); ?></span>
						<?php endif; ?>
					</div>
				</div>

				<div class="cm-actions">
					<button type="button" class="cm-btn cm-btn-secondary" data-cm-toggle>View details</button>

					<?php if ( is_user_logged_in() ) : ?>
						<button type="button"
							class="cm-btn cm-connectme"
							data-vendor-id="<?php echo esc_attr($vendor_id); ?>">
							Connect Me
						</button>
					<?php else : ?>
						<button type="button" class="cm-btn" disabled title="Login required">Connect Me</button>
					<?php endif; ?>
				</div>
			</div>

			<div class="cm-details" hidden>
				<div class="cm-grid">
					<?php if ($contact) : ?><div><strong>Contact:</strong> <?php echo esc_html($contact); ?></div><?php endif; ?>
					<?php if ($phone) : ?><div><strong>Phone:</strong> <?php echo esc_html($phone); ?></div><?php endif; ?>
					<?php if ($email) : ?><div><strong>Email:</strong> <a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></div><?php endif; ?>
					<?php if ($website) : ?><div><strong>Website:</strong> <a href="<?php echo esc_url($website); ?>" target="_blank" rel="noopener"><?php echo esc_html($website); ?></a></div><?php endif; ?>
				</div>
			</div>

			<div class="cm-note" aria-live="polite"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_pagination($page, $total_pages) {
		if ($total_pages <= 1) return '';

		$base = remove_query_arg(array('vd_page'));
		$prev = max(1, $page - 1);
		$next = min($total_pages, $page + 1);

		ob_start();
		?>
		<div class="cm-pagination">
			<a class="cm-page" href="<?php echo esc_url(add_query_arg('vd_page', $prev, $base)); ?>" <?php echo $page <= 1 ? 'aria-disabled="true"' : ''; ?>>← Prev</a>
			<span class="cm-page-info">Page <?php echo esc_html($page); ?> of <?php echo esc_html($total_pages); ?></span>
			<a class="cm-page" href="<?php echo esc_url(add_query_arg('vd_page', $next, $base)); ?>" <?php echo $page >= $total_pages ? 'aria-disabled="true"' : ''; ?>>Next →</a>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function labelize($s) {
		$s = str_replace(array('-', '_'), ' ', trim((string) $s));
		return $s === '' ? '' : ucwords($s);
	}

	/* =============================
	 * AJAX: Create Request
	 * ============================= */

	public static function ajax_create_request() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error(array('message' => 'Login required.'), 401);
		}

		check_ajax_referer(self::NONCE_KEY, 'nonce');

		$vendor_id = isset($_POST['vendor_id']) ? absint($_POST['vendor_id']) : 0;
		if ( ! $vendor_id ) {
			wp_send_json_error(array('message' => 'Missing vendor id.'), 400);
		}

		$user_id = get_current_user_id();

		$vendor = self::get_vendor_row($vendor_id);
		if ( ! $vendor ) {
			wp_send_json_error(array('message' => 'Vendor not found.'), 404);
		}

		$vendor_email = isset($vendor['email']) ? sanitize_email($vendor['email']) : '';
		if ( ! $vendor_email ) {
			wp_send_json_error(array('message' => 'Vendor email not available.'), 400);
		}

		// Prevent multiple pending requests for same requester/vendor
		$existing = self::find_pending_request($user_id, $vendor_id);
		if ( $existing ) {
			wp_send_json_success(array('message' => 'Request already sent.', 'request_id' => (int) $existing['id']));
		}

		$vendor_user = get_user_by('email', $vendor_email);
		$vendor_user_id = $vendor_user ? (int) $vendor_user->ID : null;

		$token = bin2hex(random_bytes(32)); // 64 chars

		$request_id = self::insert_request(array(
			'requester_user_id' => $user_id,
			'vendor_id'         => $vendor_id,
			'vendor_email'      => $vendor_email,
			'vendor_user_id'    => $vendor_user_id,
			'token'             => $token,
		));

		if ( ! $request_id ) {
			wp_send_json_error(array('message' => 'Failed to create request.'), 500);
		}

		$yes_link = self::build_request_response_link($token, 'yes');
		$no_link  = self::build_request_response_link($token, 'no');

		self::send_vendor_request_email(array(
			'to'                => $vendor_email,
			'vendor'            => $vendor,
			'requester_user_id' => $user_id,
			'request_id'        => $request_id,
			'yes_link'          => $yes_link,
			'no_link'           => $no_link,
		));

		// Schedule reminders (stages 1/2/3)
		self::schedule_request_reminders($request_id);

		wp_send_json_success(array('message' => 'Request sent.', 'request_id' => (int) $request_id));
	}

	private static function get_vendor_row($vendor_id) {
		global $wpdb;
		$table = self::VENDOR_TABLE;

		$row = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $vendor_id),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	private static function find_pending_request($user_id, $vendor_id) {
		global $wpdb;
		$table = self::requests_table();

		$row = $wpdb->get_row(
			$wpdb->prepare("
				SELECT * FROM {$table}
				WHERE requester_user_id = %d AND vendor_id = %d AND status = 'pending'
				ORDER BY id DESC
				LIMIT 1
			", (int) $user_id, (int) $vendor_id),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	private static function insert_request($data) {
		global $wpdb;
		$table = self::requests_table();

		$ok = $wpdb->insert($table, array(
			'requester_user_id' => (int) $data['requester_user_id'],
			'vendor_id'         => (int) $data['vendor_id'],
			'vendor_email'      => (string) $data['vendor_email'],
			'vendor_user_id'    => $data['vendor_user_id'] ? (int) $data['vendor_user_id'] : null,
			'status'            => 'pending',
			'token'             => (string) $data['token'],
			'created_at'        => current_time('mysql'),
		));

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/* =============================
	 * Routes / Query Vars
	 * ============================= */

	public static function query_vars($vars) {
		$vars[] = 'cm_token';
		$vars[] = 'cm_action';
		$vars[] = 'cm_vendor_id';
		return $vars;
	}

	public static function handle_routes() {
		$uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

		// Request response route
		if ( strpos($uri, self::RESPOND_PATH) !== false ) {
			self::handle_request_response_route();
			return;
		}

		// Vendor opt-in routes
		if ( strpos($uri, self::OPTIN_VERIFY_PATH) !== false ) {
			self::handle_vendor_verify_route();
			return;
		}
		if ( strpos($uri, self::OPTIN_DECLINE_PATH) !== false ) {
			self::handle_vendor_decline_route();
			return;
		}
	}

	private static function build_request_response_link($token, $action) {
		return add_query_arg(array(
			'cm_token'  => $token,
			'cm_action' => $action,
		), home_url(self::RESPOND_PATH));
	}

	/* =============================
	 * Request Response Handler (Yes/No)
	 * ============================= */

	private static function handle_request_response_route() {
		$token  = isset($_GET['cm_token']) ? sanitize_text_field(wp_unslash($_GET['cm_token'])) : '';
		$action = isset($_GET['cm_action']) ? sanitize_text_field(wp_unslash($_GET['cm_action'])) : '';

		if ( ! $token || ! in_array($action, array('yes','no'), true) ) {
			self::render_simple_page('Invalid response link.');
		}

		$new_status = ($action === 'yes') ? 'approved' : 'declined';

		$result = self::apply_request_response($token, $new_status);

		if ( $result === 'not_found' ) {
			self::render_simple_page('This link is invalid or has expired.');
		}
		if ( $result === 'already_done' ) {
			self::render_simple_page('This request was already processed.');
		}

		self::render_simple_page(
			$new_status === 'approved'
				? 'Thanks! You confirmed: Yes, Connect Me!'
				: 'Thanks! You confirmed: No, I’m not interested.'
		);
	}

	private static function apply_request_response($token, $status) {
		global $wpdb;
		$table = self::requests_table();

		$row = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$table} WHERE token = %s LIMIT 1", (string) $token),
			ARRAY_A
		);

		if ( ! $row ) return 'not_found';
		if ( $row['status'] !== 'pending' || ! empty($row['responded_at']) ) return 'already_done';

		$ip = sanitize_text_field(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
		$ua = sanitize_text_field(substr(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 0, 255));

		$ok = $wpdb->update($table, array(
			'status'       => (string) $status,
			'responded_at' => current_time('mysql'),
			'response_ip'  => $ip,
			'response_ua'  => $ua,
		), array(
			'id' => (int) $row['id'],
		));

		if ( $ok === false ) return 'not_found';

		// Notify requester once vendor responds
		self::notify_requester_on_vendor_response((int) $row['requester_user_id'], $row, $status);

		return 'updated';
	}

	private static function notify_requester_on_vendor_response($requester_user_id, $request_row, $status) {
		$requester = get_userdata((int) $requester_user_id);
		if ( ! $requester || empty($requester->user_email) ) return;

		$vendor = self::get_vendor_row((int) $request_row['vendor_id']);
		$vendor_name = $vendor && ! empty($vendor['company_name']) ? $vendor['company_name'] : 'the vendor';

		$subject = 'ConnectMe: Vendor responded';
		$body = "Hi,\n\n";
		$body .= "{$vendor_name} responded to your ConnectMe request.\n";
		$body .= "Response: " . strtoupper($status) . "\n\n";
		$body .= "Thanks,\nConnectMe\n";

		if ( self::EMAIL_DRY_RUN ) {
			error_log('[ConnectMe][DRY RUN] requester notify to=' . $requester->user_email . ' status=' . $status);
			return;
		}

		wp_mail($requester->user_email, $subject, $body);
	}

	/* =============================
	 * Reminders (WP-Cron)
	 * ============================= */

	private static function schedule_request_reminders($request_id) {
		$request_id = (int) $request_id;
		if ( $request_id <= 0 ) return;

		// Stage numbering: 1,2,3
		// We schedule based on "now + N days" for simplicity.
		foreach (self::$reminder_days as $idx => $days) {
			$stage = $idx + 1;
			$ts = time() + ( (int) $days * DAY_IN_SECONDS );

			// Avoid duplicates
			if ( ! wp_next_scheduled('connectme_send_reminder', array($request_id, $stage)) ) {
				wp_schedule_single_event($ts, 'connectme_send_reminder', array($request_id, $stage));
			}
		}
	}

	public static function cron_send_reminder($request_id, $stage) {
		$request_id = (int) $request_id;
		$stage      = (int) $stage;

		if ( $request_id <= 0 || $stage <= 0 ) return;

		global $wpdb;
		$table = self::requests_table();

		$row = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $request_id),
			ARRAY_A
		);

		if ( ! $row ) return;

		// Only remind pending requests
		if ( $row['status'] !== 'pending' ) return;

		// Update reminder stage marker
		$wpdb->update($table, array(
			'reminder_stage'  => $stage,
			'last_reminded_at'=> current_time('mysql'),
		), array('id' => $request_id), array('%d','%s'), array('%d'));

		// Send reminder email to vendor
		$yes_link = self::build_request_response_link($row['token'], 'yes');
		$no_link  = self::build_request_response_link($row['token'], 'no');

		$vendor = self::get_vendor_row((int) $row['vendor_id']);
		$vendor_name = $vendor && ! empty($vendor['company_name']) ? $vendor['company_name'] : 'Vendor';

		$subject = 'ConnectMe Reminder (Stage ' . $stage . ')';
		$body  = "Hi {$vendor_name},\n\n";
		$body .= "This is a reminder regarding a ConnectMe request.\n\n";
		$body .= "YES: {$yes_link}\n";
		$body .= "NO:  {$no_link}\n\n";
		$body .= "Thanks,\nConnectMe\n";

		if ( self::EMAIL_DRY_RUN ) {
			error_log('[ConnectMe][DRY RUN] reminder stage=' . $stage . ' to=' . $row['vendor_email']);
			error_log('[ConnectMe][DRY RUN] YES=' . $yes_link);
			error_log('[ConnectMe][DRY RUN] NO=' . $no_link);
			return;
		}

		wp_mail($row['vendor_email'], $subject, $body);
	}

	/* =============================
	 * Vendor Opt-in (Greg's New Requirement)
	 * ============================= */

	/**
	 * Callable function: generates token, stores hash+expiry on vendor row, sends email with Verify/Decline links.
	 * Greg will wire the trigger point when vendors are added.
	 */
	public static function send_vendor_optin_email($vendor_id) {
		$vendor_id = absint($vendor_id);
		if ( ! $vendor_id ) return false;

		global $wpdb;
		$table = self::VENDOR_TABLE;

		$vendor = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $vendor_id),
			ARRAY_A
		);

		if ( ! $vendor ) {
			error_log('[ConnectMe][OptIn] Vendor not found. id=' . $vendor_id);
			return false;
		}

		$email = isset($vendor['email']) ? sanitize_email($vendor['email']) : '';
		if ( ! $email ) {
			error_log('[ConnectMe][OptIn] Vendor missing email. id=' . $vendor_id);
			return false;
		}

		$raw_token  = bin2hex(random_bytes(32));
		$token_hash = hash('sha256', $raw_token);
		$expires_at = gmdate('Y-m-d H:i:s', time() + (self::OPTIN_TOKEN_TTL_HOURS * HOUR_IN_SECONDS));

		$ok = $wpdb->update($table, array(
			'connectme_token_hash'       => $token_hash,
			'connectme_token_expires_at' => $expires_at,
		), array('id' => $vendor_id), array('%s','%s'), array('%d'));

		if ( $ok === false ) {
			error_log('[ConnectMe][OptIn] Failed storing token. id=' . $vendor_id);
			return false;
		}

		$verify_url = add_query_arg(array(
			'cm_vendor_id' => $vendor_id,
			'cm_token'     => $raw_token,
		), home_url(self::OPTIN_VERIFY_PATH));

		$decline_url = add_query_arg(array(
			'cm_vendor_id' => $vendor_id,
			'cm_token'     => $raw_token,
		), home_url(self::OPTIN_DECLINE_PATH));

		$company = ! empty($vendor['company_name']) ? $vendor['company_name'] : 'there';

		$subject = 'ConnectMe: Please verify your vendor contact details';
		$body  = "Hi {$company},\n\n";
		$body .= "We are adding vendors to the ConnectMe system.\n\n";
		$body .= "If you'd like to be included, click here to verify your contact details:\n{$verify_url}\n\n";
		$body .= "I do not wish to participate in the 'ConnectMe!' system:\n{$decline_url}\n\n";
		$body .= "Thanks,\nConnectMe\n";

		if ( self::EMAIL_DRY_RUN ) {
			error_log('[ConnectMe][OptIn][DRY RUN] to=' . $email);
			error_log('[ConnectMe][OptIn][DRY RUN] verify=' . $verify_url);
			error_log('[ConnectMe][OptIn][DRY RUN] decline=' . $decline_url);
			return true;
		}

		return wp_mail($email, $subject, $body);
	}

	private static function handle_vendor_decline_route() {
		$vendor_id = isset($_GET['cm_vendor_id']) ? absint($_GET['cm_vendor_id']) : 0;
		$token     = isset($_GET['cm_token']) ? sanitize_text_field(wp_unslash($_GET['cm_token'])) : '';

		if ( ! $vendor_id || ! $token ) {
			self::render_simple_page('Invalid link.');
		}

		$vendor = self::validate_vendor_optin_token($vendor_id, $token);
		if ( ! $vendor ) {
			self::render_simple_page('This link is invalid or has expired.');
		}

		self::set_vendor_optin($vendor_id, 0);
		self::clear_vendor_optin_token($vendor_id);

		self::render_simple_page("Thanks — you’ve declined participation in ConnectMe.");
	}

	private static function handle_vendor_verify_route() {
		$vendor_id = isset($_GET['cm_vendor_id']) ? absint($_GET['cm_vendor_id']) : 0;
		$token     = isset($_GET['cm_token']) ? sanitize_text_field(wp_unslash($_GET['cm_token'])) : '';

		if ( ! $vendor_id || ! $token ) {
			self::render_simple_page('Invalid link.');
		}

		$vendor = self::validate_vendor_optin_token($vendor_id, $token);
		if ( ! $vendor ) {
			self::render_simple_page('This link is invalid or has expired.');
		}

		if ( strtoupper($_SERVER['REQUEST_METHOD']) === 'POST' ) {
			check_admin_referer('connectme_vendor_verify_' . $vendor_id);

			$updates = array(
				// Adjust these if your vendor table uses different column names
				'company_name' => isset($_POST['company_name']) ? sanitize_text_field(wp_unslash($_POST['company_name'])) : (string) ($vendor['company_name'] ?? ''),
				'contact_name' => isset($_POST['contact_name']) ? sanitize_text_field(wp_unslash($_POST['contact_name'])) : (string) ($vendor['contact_name'] ?? ''),
				'email'        => isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : (string) ($vendor['email'] ?? ''),
				'phone'        => isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : (string) ($vendor['phone'] ?? ''),
				'website'      => isset($_POST['website']) ? esc_url_raw(wp_unslash($_POST['website'])) : (string) ($vendor['website'] ?? ''),
			);

			self::update_vendor_contact_fields($vendor_id, $updates);
			self::set_vendor_optin($vendor_id, 1);
			self::clear_vendor_optin_token($vendor_id);

			self::render_simple_page("Thanks — your details have been verified and you’re now included in ConnectMe.");
		}

		self::render_vendor_verify_form($vendor_id, $vendor);
	}

	private static function validate_vendor_optin_token($vendor_id, $raw_token) {
		global $wpdb;
		$table = self::VENDOR_TABLE;

		$vendor = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $vendor_id),
			ARRAY_A
		);
		if ( ! $vendor ) return null;

		$stored_hash = isset($vendor['connectme_token_hash']) ? (string) $vendor['connectme_token_hash'] : '';
		$expires_at  = isset($vendor['connectme_token_expires_at']) ? (string) $vendor['connectme_token_expires_at'] : '';

		if ( ! $stored_hash || ! $expires_at ) return null;

		$expires_ts = strtotime($expires_at . ' UTC');
		if ( ! $expires_ts || time() > $expires_ts ) return null;

		$calc_hash = hash('sha256', (string) $raw_token);
		if ( ! hash_equals($stored_hash, $calc_hash) ) return null;

		return $vendor;
	}

	private static function set_vendor_optin($vendor_id, $opt_in) {
		global $wpdb;
		$table = self::VENDOR_TABLE;

		$wpdb->update($table, array(
			'connectme_opt_in'      => (int) $opt_in,
			'connectme_verified_at' => current_time('mysql'),
		), array('id' => (int) $vendor_id), array('%d','%s'), array('%d'));
	}

	private static function clear_vendor_optin_token($vendor_id) {
		global $wpdb;
		$table = self::VENDOR_TABLE;

		$wpdb->update($table, array(
			'connectme_token_hash'       => null,
			'connectme_token_expires_at' => null,
		), array('id' => (int) $vendor_id), array('%s','%s'), array('%d'));
	}

	private static function update_vendor_contact_fields($vendor_id, $updates) {
		global $wpdb;
		$table = self::VENDOR_TABLE;

		$allowed = array('company_name','contact_name','email','phone','website');

		$data = array();
		$fmt  = array();

		foreach ($allowed as $k) {
			if ( array_key_exists($k, $updates) ) {
				$data[$k] = $updates[$k];
				$fmt[] = '%s';
			}
		}

		if ( empty($data) ) return;

		$wpdb->update($table, $data, array('id' => (int) $vendor_id), $fmt, array('%d'));
	}

	private static function render_vendor_verify_form($vendor_id, $vendor) {
		$company = esc_attr($vendor['company_name'] ?? '');
		$contact = esc_attr($vendor['contact_name'] ?? '');
		$email   = esc_attr($vendor['email'] ?? '');
		$phone   = esc_attr($vendor['phone'] ?? '');
		$website = esc_attr($vendor['website'] ?? '');

		ob_start();
		?>
		<div style="max-width:760px;margin:40px auto;padding:18px;border:1px solid #e6e6e6;border-radius:12px;font-family:Arial;background:#fff">
			<h2 style="margin-top:0">ConnectMe — Verify Contact Details</h2>
			<p>Please confirm or update your contact details below.</p>

			<form method="post">
				<?php wp_nonce_field('connectme_vendor_verify_' . (int) $vendor_id); ?>

				<label style="display:block;margin:12px 0 6px;font-weight:700;">Company Name</label>
				<input name="company_name" value="<?php echo $company; ?>" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:10px">

				<label style="display:block;margin:12px 0 6px;font-weight:700;">Contact Name</label>
				<input name="contact_name" value="<?php echo $contact; ?>" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:10px">

				<label style="display:block;margin:12px 0 6px;font-weight:700;">Email</label>
				<input name="email" type="email" value="<?php echo $email; ?>" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:10px">

				<label style="display:block;margin:12px 0 6px;font-weight:700;">Phone</label>
				<input name="phone" value="<?php echo $phone; ?>" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:10px">

				<label style="display:block;margin:12px 0 6px;font-weight:700;">Website</label>
				<input name="website" value="<?php echo $website; ?>" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:10px">

				<button type="submit" style="margin-top:16px;padding:10px 14px;border-radius:10px;border:1px solid #222;background:#222;color:#fff;cursor:pointer">
					Save & Participate
				</button>
			</form>
		</div>
		<?php
		wp_die(ob_get_clean(), 'ConnectMe', array('response' => 200));
		exit;
	}

	/* =============================
	 * Vendor Request Email
	 * ============================= */

	private static function send_vendor_request_email($args) {
		$to  = $args['to'];
		$yes = $args['yes_link'];
		$no  = $args['no_link'];

		$vendor_name = isset($args['vendor']['company_name']) ? $args['vendor']['company_name'] : 'Vendor';
		$requester   = get_userdata((int) $args['requester_user_id']);
		$requester_name = $requester ? ($requester->display_name ? $requester->display_name : $requester->user_login) : 'A user';

		if ( self::EMAIL_DRY_RUN ) {
			error_log('[ConnectMe][DRY RUN] Request to=' . $to);
			error_log('[ConnectMe][DRY RUN] Vendor=' . $vendor_name . ' | Requester=' . $requester_name);
			error_log('[ConnectMe][DRY RUN] YES=' . $yes);
			error_log('[ConnectMe][DRY RUN] NO=' . $no);
			return true;
		}

		$subject = 'ConnectMe request';
		$body  = "Hi {$vendor_name},\n\n";
		$body .= "{$requester_name} would like to connect.\n\n";
		$body .= "YES: {$yes}\n";
		$body .= "NO:  {$no}\n\n";
		$body .= "Thanks,\nConnectMe\n";

		return wp_mail($to, $subject, $body);
	}

	/* =============================
	 * Shared response page
	 * ============================= */

	private static function render_simple_page($message) {
		wp_die(
			'<div style="max-width:760px;margin:40px auto;padding:18px;border:1px solid #e6e6e6;border-radius:12px;font-family:Arial;background:#fff">' .
			'<h2 style="margin-top:0">ConnectMe</h2>' .
			'<p>' . esc_html($message) . '</p>' .
			'</div>',
			'ConnectMe',
			array('response' => 200)
		);
		exit;
	}

	/* =============================
	 * CSS / JS
	 * ============================= */

	private static function css() {
		return "
		.cm-wrap{max-width:980px;margin:20px auto;padding:16px;border:1px solid #e7e7e7;border-radius:12px;background:#fff}
		.cm-head{margin-bottom:12px}
		.cm-title{margin:0 0 6px;font-size:20px}
		.cm-sub{margin:0;color:#666}
		.cm-filters{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin:14px 0 18px}
		.cm-filters label{display:flex;flex-direction:column;gap:6px;font-size:13px}
		.cm-filters select{min-width:180px;padding:8px;border:1px solid #ddd;border-radius:10px}
		.cm-btn{padding:9px 12px;border-radius:10px;border:1px solid #222;background:#222;color:#fff;cursor:pointer}
		.cm-btn[disabled]{opacity:.5;cursor:not-allowed}
		.cm-btn-secondary{background:#fff;color:#222}
		.cm-link{color:#222;text-decoration:underline;padding:8px 0}
		.cm-list{display:flex;flex-direction:column;gap:10px}
		.cm-card{border:1px solid #eee;border-radius:12px;padding:12px}
		.cm-card-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
		.cm-company{font-weight:700;font-size:16px}
		.cm-meta{display:flex;gap:10px;align-items:center;margin-top:6px}
		.cm-pill{display:inline-block;padding:4px 8px;border:1px solid #ddd;border-radius:999px;font-size:12px;background:#fafafa}
		.cm-muted{color:#666;font-size:12px}
		.cm-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
		.cm-details{margin-top:12px;padding-top:12px;border-top:1px dashed #eee}
		.cm-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
		.cm-empty{padding:14px;border:1px dashed #ddd;border-radius:12px;color:#666}
		.cm-pagination{display:flex;justify-content:space-between;align-items:center;margin-top:16px}
		.cm-page{padding:8px 10px;border:1px solid #ddd;border-radius:10px;text-decoration:none;color:#222}
		.cm-page[aria-disabled='true']{opacity:.5;pointer-events:none}
		.cm-note{margin-top:10px;font-size:13px;color:#0a6}
		@media(max-width:640px){.cm-grid{grid-template-columns:1fr}.cm-card-top{flex-direction:column}}
		";
	}

	private static function js() {
		return "
		(function(){
		  // expand details
		  document.addEventListener('click', function(e){
			var btn = e.target.closest('[data-cm-toggle]');
			if(!btn) return;
			var card = btn.closest('.cm-card');
			if(!card) return;
			var details = card.querySelector('.cm-details');
			if(!details) return;
			var isHidden = details.hasAttribute('hidden');
			if(isHidden){ details.removeAttribute('hidden'); btn.textContent='Hide details'; }
			else{ details.setAttribute('hidden','hidden'); btn.textContent='View details'; }
		  });

		  // connect me
		  document.addEventListener('click', async function(e){
			var btn = e.target.closest('.cm-connectme');
			if(!btn) return;

			var card = btn.closest('.cm-card');
			var note = card ? card.querySelector('.cm-note') : null;

			var vendorId = btn.getAttribute('data-vendor-id');
			btn.disabled = true;
			var old = btn.textContent;
			btn.textContent = 'Sending...';
			if(note) note.textContent = '';

			var form = new URLSearchParams();
			form.append('action', " . json_encode(self::AJAX_ACTION) . ");
			form.append('nonce', (window.ConnectMe && window.ConnectMe.nonce) ? window.ConnectMe.nonce : '');
			form.append('vendor_id', vendorId);

			try {
			  var res = await fetch((window.ConnectMe && window.ConnectMe.ajaxUrl) ? window.ConnectMe.ajaxUrl : '', {
				method: 'POST',
				headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
				body: form.toString()
			  });

			  var json = await res.json().catch(function(){ return null; });

			  if(json && json.success){
				btn.textContent = 'Sent';
				if(note) note.textContent = json.data && json.data.message ? json.data.message : 'Request sent.';
			  } else {
				btn.disabled = false;
				btn.textContent = old;
				var msg = (json && json.data && json.data.message) ? json.data.message : 'Failed. Please try again.';
				if(note){ note.style.color='#b00'; note.textContent = msg; }
			  }
			} catch(err){
			  btn.disabled = false;
			  btn.textContent = old;
			  if(note){ note.style.color='#b00'; note.textContent = 'Network error. Please try again.'; }
			}
		  });
		})();
		";
	}
}

ConnectMe_System::init();