<?php
/**
 * Priority Rules Admin Page
 *
 * @package EightyFourEM\FileIntegrityChecker\Admin
 */

namespace EightyFourEM\FileIntegrityChecker\Admin;

use EightyFourEM\FileIntegrityChecker\Database\PriorityRulesRepository;
use EightyFourEM\FileIntegrityChecker\Services\LoggerService;

/**
 * Manages priority rules admin interface
 */
class PriorityRulesPage {
	/**
	 * Priority rules repository
	 *
	 * @var PriorityRulesRepository
	 */
	private PriorityRulesRepository $repository;

	/**
	 * Logger service
	 *
	 * @var LoggerService
	 */
	private LoggerService $logger;

	/**
	 * Constructor
	 *
	 * @param PriorityRulesRepository $repository Priority rules repository
	 * @param LoggerService           $logger     Logger service
	 */
	public function __construct( PriorityRulesRepository $repository, LoggerService $logger ) {
		$this->repository = $repository;
		$this->logger     = $logger;
	}

	/**
	 * Initialize admin page
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'addMenuPage' ], 20 );
		add_action( 'admin_post_eightyfourem_save_priority_rule', [ $this, 'handleSaveRule' ] );
		add_action( 'admin_post_eightyfourem_delete_priority_rule', [ $this, 'handleDeleteRule' ] );
		add_action( 'admin_post_eightyfourem_install_default_rules', [ $this, 'handleInstallDefaults' ] );
	}

	/**
	 * Add menu page
	 */
	public function addMenuPage(): void {
		add_submenu_page(
			'eightyfourem-file-integrity',
			'Priority Rules',
			'Priority Rules',
			'manage_options',
			'eightyfourem-priority-rules',
			[ $this, 'renderPage' ]
		);
	}

	/**
	 * Render admin page
	 */
	public function renderPage(): void {
		$action = $_GET['action'] ?? 'list';
		$rule_id = isset( $_GET['rule_id'] ) ? absint( $_GET['rule_id'] ) : 0;

		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html( get_admin_page_title() ); ?>
				<?php if ( $action === 'list' ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=eightyfourem-priority-rules&action=add' ) ); ?>" class="page-title-action">Add New</a>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eightyfourem_install_default_rules' ), 'install_defaults' ) ); ?>" class="page-title-action">Install Default Rules</a>
				<?php endif; ?>
			</h1>

			<?php $this->showNotices(); ?>

			<?php
			switch ( $action ) {
				case 'add':
				case 'edit':
					$this->renderForm( $rule_id );
					break;
				default:
					$this->renderList();
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Show admin notices
	 */
	private function showNotices(): void {
		if ( isset( $_GET['message'] ) ) {
			$message = sanitize_text_field( $_GET['message'] );
			$class = 'notice notice-success is-dismissible';

			$messages = [
				'saved' => 'Priority rule saved successfully.',
				'deleted' => 'Priority rule deleted successfully.',
				'defaults_installed' => 'Default priority rules installed successfully.',
			];

			if ( isset( $messages[ $message ] ) ) {
				printf( '<div class="%s"><p>%s</p></div>', esc_attr( $class ), esc_html( $messages[ $message ] ) );
			}
		}

		if ( isset( $_GET['error'] ) ) {
			$error = sanitize_text_field( $_GET['error'] );
			$class = 'notice notice-error is-dismissible';
			printf( '<div class="%s"><p>%s</p></div>', esc_attr( $class ), esc_html( $error ) );
		}
	}

	/**
	 * Render rules list
	 */
	private function renderList(): void {
		$rules = $this->repository->findAll( [ 'order_by' => 'execution_order', 'order' => 'ASC' ] );

		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Path</th>
					<th>Priority</th>
					<th>Match Type</th>
					<th>Notify</th>
					<th>Active</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rules ) ) : ?>
					<tr>
						<td colspan="6">No priority rules found. <a href="<?php echo esc_url( admin_url( 'admin.php?page=eightyfourem-priority-rules&action=add' ) ); ?>">Add your first rule</a> or <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eightyfourem_install_default_rules' ), 'install_defaults' ) ); ?>">install default rules</a>.</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $rules as $rule ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $rule->path ); ?></strong>
								<?php if ( $rule->reason ) : ?>
									<br><small><?php echo esc_html( $rule->reason ); ?></small>
								<?php endif; ?>
							</td>
							<td>
								<span class="priority-badge priority-<?php echo esc_attr( $rule->priority_level ); ?>">
									<?php echo esc_html( ucfirst( $rule->priority_level ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( ucfirst( $rule->match_type ) ); ?></td>
							<td><?php echo $rule->notify_immediately ? '✓' : '—'; ?></td>
							<td><?php echo $rule->is_active ? '<span style="color: green;">●</span>' : '<span style="color: red;">●</span>'; ?></td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=eightyfourem-priority-rules&action=edit&rule_id=' . $rule->id ) ); ?>">Edit</a> |
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eightyfourem_delete_priority_rule&rule_id=' . $rule->id ), 'delete_rule_' . $rule->id ) ); ?>" onclick="return confirm('Are you sure?')">Delete</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<style>
			.priority-badge {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: 600;
				text-transform: uppercase;
			}
			.priority-critical {
				background: #dc3232;
				color: white;
			}
			.priority-high {
				background: #f56e28;
				color: white;
			}
			.priority-normal {
				background: #46b450;
				color: white;
			}
		</style>
		<?php
	}

	/**
	 * Render add/edit form
	 *
	 * @param int $rule_id Rule ID (0 for new)
	 */
	private function renderForm( int $rule_id ): void {
		$rule = null;
		if ( $rule_id > 0 ) {
			$rule = $this->repository->find( $rule_id );
			if ( ! $rule ) {
				wp_die( 'Rule not found.' );
			}
		}

		$defaults = [
			'path'                      => '',
			'path_type'                 => 'file',
			'priority_level'            => 'high',
			'match_type'                => 'exact',
			'reason'                    => '',
			'notify_immediately'        => 0,
			'ignore_in_bulk_changes'    => 0,
			'change_velocity_threshold' => '',
			'velocity_window_hours'     => 24,
			'execution_order'           => 100,
			'is_active'                 => 1,
		];

		$data = $rule ? (array) $rule : $defaults;

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="eightyfourem_save_priority_rule">
			<?php if ( $rule_id > 0 ) : ?>
				<input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule_id ); ?>">
			<?php endif; ?>
			<?php wp_nonce_field( 'save_priority_rule' ); ?>

			<table class="form-table">
				<tr>
					<th><label for="path">File Path *</label></th>
					<td>
						<input type="text" name="path" id="path" value="<?php echo esc_attr( $data['path'] ); ?>" class="regular-text" required>
						<p class="description">Full path or pattern. Examples: /wp-config.php, /wp-admin/*, *.php</p>
					</td>
				</tr>
				<tr>
					<th><label for="match_type">Match Type *</label></th>
					<td>
						<select name="match_type" id="match_type" required>
							<option value="exact" <?php selected( $data['match_type'], 'exact' ); ?>>Exact Match</option>
							<option value="prefix" <?php selected( $data['match_type'], 'prefix' ); ?>>Prefix (starts with)</option>
							<option value="suffix" <?php selected( $data['match_type'], 'suffix' ); ?>>Suffix (ends with)</option>
							<option value="contains" <?php selected( $data['match_type'], 'contains' ); ?>>Contains</option>
							<option value="glob" <?php selected( $data['match_type'], 'glob' ); ?>>Glob Pattern</option>
							<option value="regex" <?php selected( $data['match_type'], 'regex' ); ?>>Regular Expression</option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="priority_level">Priority Level *</label></th>
					<td>
						<select name="priority_level" id="priority_level" required>
							<option value="critical" <?php selected( $data['priority_level'], 'critical' ); ?>>Critical</option>
							<option value="high" <?php selected( $data['priority_level'], 'high' ); ?>>High</option>
							<option value="normal" <?php selected( $data['priority_level'], 'normal' ); ?>>Normal</option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="reason">Reason</label></th>
					<td>
						<textarea name="reason" id="reason" rows="3" class="large-text"><?php echo esc_textarea( $data['reason'] ); ?></textarea>
						<p class="description">Why is this file/path prioritized?</p>
					</td>
				</tr>
				<tr>
					<th>Notifications</th>
					<td>
						<label>
							<input type="checkbox" name="notify_immediately" value="1" <?php checked( $data['notify_immediately'], 1 ); ?>>
							Send immediate notification when changed
						</label>
						<br>
						<label>
							<input type="checkbox" name="ignore_in_bulk_changes" value="1" <?php checked( $data['ignore_in_bulk_changes'], 1 ); ?>>
							Ignore during bulk changes (e.g., plugin updates)
						</label>
					</td>
				</tr>
				<tr>
					<th><label for="change_velocity_threshold">Velocity Threshold</label></th>
					<td>
						<input type="number" name="change_velocity_threshold" id="change_velocity_threshold" value="<?php echo esc_attr( $data['change_velocity_threshold'] ); ?>" min="1" class="small-text">
						changes within
						<input type="number" name="velocity_window_hours" id="velocity_window_hours" value="<?php echo esc_attr( $data['velocity_window_hours'] ); ?>" min="1" class="small-text">
						hours
						<p class="description">Alert if file changes exceed this threshold. Leave blank to disable velocity tracking.</p>
					</td>
				</tr>
				<tr>
					<th><label for="execution_order">Execution Order</label></th>
					<td>
						<input type="number" name="execution_order" id="execution_order" value="<?php echo esc_attr( $data['execution_order'] ); ?>" min="1" class="small-text">
						<p class="description">Lower numbers are evaluated first. Default: 100</p>
					</td>
				</tr>
				<tr>
					<th>Status</th>
					<td>
						<label>
							<input type="checkbox" name="is_active" value="1" <?php checked( $data['is_active'], 1 ); ?>>
							Active
						</label>
					</td>
				</tr>
			</table>

			<p class="submit">
				<input type="submit" name="submit" class="button button-primary" value="<?php echo $rule_id > 0 ? 'Update Rule' : 'Add Rule'; ?>">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=eightyfourem-priority-rules' ) ); ?>" class="button">Cancel</a>
			</p>
		</form>
		<?php
	}

	/**
	 * Handle save rule
	 */
	public function handleSaveRule(): void {
		check_admin_referer( 'save_priority_rule' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		$rule_id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;

		$data = [
			'path'                      => sanitize_text_field( $_POST['path'] ),
			'path_type'                 => 'file',
			'priority_level'            => sanitize_text_field( $_POST['priority_level'] ),
			'match_type'                => sanitize_text_field( $_POST['match_type'] ),
			'reason'                    => sanitize_textarea_field( $_POST['reason'] ?? '' ),
			'notify_immediately'        => isset( $_POST['notify_immediately'] ) ? 1 : 0,
			'ignore_in_bulk_changes'    => isset( $_POST['ignore_in_bulk_changes'] ) ? 1 : 0,
			'change_velocity_threshold' => ! empty( $_POST['change_velocity_threshold'] ) ? absint( $_POST['change_velocity_threshold'] ) : null,
			'velocity_window_hours'     => absint( $_POST['velocity_window_hours'] ?? 24 ),
			'execution_order'           => absint( $_POST['execution_order'] ?? 100 ),
			'is_active'                 => isset( $_POST['is_active'] ) ? 1 : 0,
		];

		if ( $rule_id > 0 ) {
			$success = $this->repository->update( $rule_id, $data );
		} else {
			$success = $this->repository->create( $data );
		}

		if ( $success ) {
			$this->logger->info(
				$rule_id > 0 ? 'Priority rule updated' : 'Priority rule created',
				'priority_rules',
				[ 'rule_id' => $rule_id, 'path' => $data['path'] ]
			);
			wp_safe_redirect( admin_url( 'admin.php?page=eightyfourem-priority-rules&message=saved' ) );
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=eightyfourem-priority-rules&error=Failed to save rule' ) );
		}
		exit;
	}

	/**
	 * Handle delete rule
	 */
	public function handleDeleteRule(): void {
		$rule_id = isset( $_GET['rule_id'] ) ? absint( $_GET['rule_id'] ) : 0;

		check_admin_referer( 'delete_rule_' . $rule_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		if ( $this->repository->delete( $rule_id ) ) {
			$this->logger->info( 'Priority rule deleted', 'priority_rules', [ 'rule_id' => $rule_id ] );
			wp_safe_redirect( admin_url( 'admin.php?page=eightyfourem-priority-rules&message=deleted' ) );
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=eightyfourem-priority-rules&error=Failed to delete rule' ) );
		}
		exit;
	}

	/**
	 * Handle install default rules
	 */
	public function handleInstallDefaults(): void {
		check_admin_referer( 'install_defaults' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		$installed = $this->installDefaultRules();

		if ( $installed > 0 ) {
			$this->logger->info( "Installed $installed default priority rules", 'priority_rules' );
			wp_safe_redirect( admin_url( 'admin.php?page=eightyfourem-priority-rules&message=defaults_installed' ) );
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=eightyfourem-priority-rules&error=No new rules to install' ) );
		}
		exit;
	}

	/**
	 * Install default priority rules
	 *
	 * @return int Number of rules installed
	 */
	private function installDefaultRules(): int {
		$default_rules = [
			[
				'path'               => '/wp-config.php',
				'priority_level'     => 'critical',
				'match_type'         => 'exact',
				'reason'             => 'WordPress configuration file - contains database credentials',
				'notify_immediately' => 1,
				'execution_order'    => 10,
			],
			[
				'path'               => '/.htaccess',
				'priority_level'     => 'critical',
				'match_type'         => 'exact',
				'reason'             => 'Apache configuration - controls access and rewrites',
				'notify_immediately' => 1,
				'execution_order'    => 15,
			],
			[
				'path'               => '/.env',
				'priority_level'     => 'critical',
				'match_type'         => 'exact',
				'reason'             => 'Environment configuration file',
				'notify_immediately' => 1,
				'execution_order'    => 20,
			],
			[
				'path'               => '/wp-content/mu-plugins/*',
				'priority_level'     => 'high',
				'match_type'         => 'prefix',
				'reason'             => 'Must-use plugins - automatically loaded',
				'notify_immediately' => 1,
				'execution_order'    => 30,
			],
			[
				'path'               => '/wp-admin/index.php',
				'priority_level'     => 'high',
				'match_type'         => 'exact',
				'reason'             => 'Admin dashboard entry point',
				'execution_order'    => 40,
			],
			[
				'path'               => '/wp-login.php',
				'priority_level'     => 'high',
				'match_type'         => 'exact',
				'reason'             => 'Login page - common attack target',
				'execution_order'    => 45,
			],
			[
				'path'               => '/wp-content/uploads/*.php',
				'priority_level'     => 'critical',
				'match_type'         => 'glob',
				'reason'             => 'PHP files in uploads directory - potential backdoor',
				'notify_immediately' => 1,
				'execution_order'    => 5,
			],
		];

		$installed = 0;
		foreach ( $default_rules as $rule ) {
			// Check if rule already exists
			$existing = $this->repository->findAll( [
				'limit' => 1,
			] );

			$exists = false;
			foreach ( $existing as $existing_rule ) {
				if ( $existing_rule->path === $rule['path'] && $existing_rule->match_type === $rule['match_type'] ) {
					$exists = true;
					break;
				}
			}

			if ( ! $exists ) {
				if ( $this->repository->create( $rule ) ) {
					$installed++;
				}
			}
		}

		return $installed;
	}
}
