<?php
/**
 * Plugin Name:       Hervyho blokátor komentářů
 * Description:       Kompletně vypne komentáře, pingbacky a trackbacky, skryje je v administraci a umožní hromadně uzavřít a smazat existující komentáře. Nastavení: Nastavení → Blokátor komentářů.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Hervy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hervyho-blokator-komentaru
 */

defined( 'ABSPATH' ) || exit;

final class Hervy_Comment_Blocker {

	const OPTION = 'hbk_settings';
	const GROUP  = 'hbk_settings_group';
	const SLUG   = 'hervyho-blokator-komentaru';
	const BATCH  = 500;

	/** Typy komentářů, které čistka maže. Ostatní (recenze, poznámky k objednávkám, logy) nechává být. */
	const DELETABLE_COMMENT_TYPES = array( 'comment', '', 'pingback', 'trackback' );

	/** Bloky, které se na webu nevykreslí (pokud daný obsah není ve výjimkách). */
	const COMMENT_BLOCKS = array(
		'core/comments',
		'core/comments-title',
		'core/comment-template',
		'core/comments-pagination',
		'core/post-comments',
		'core/post-comments-form',
		'core/post-comments-count',
		'core/post-comments-link',
	);

	/** @var array<string,string> Typy obsahu, které podporovaly komentáře před zásahem pluginu (slug => název). */
	private static $commentable_types = array();

	/** @var array|null */
	private static $settings = null;

	/* --------------------------------------------------------------------
	 * Nastavení
	 * ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'enabled'        => 1,
			'hide_admin'     => 1,
			'disable_api'    => 1,
			'excluded_types' => array(),
		);
	}

	public static function settings() {
		if ( null === self::$settings ) {
			$saved = get_option( self::OPTION, array() );
			$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );

			$s['enabled']        = (int) ! empty( $s['enabled'] );
			$s['hide_admin']     = (int) ! empty( $s['hide_admin'] );
			$s['disable_api']    = (int) ! empty( $s['disable_api'] );
			$s['excluded_types'] = array_values( array_filter( (array) $s['excluded_types'], 'is_string' ) );

			self::$settings = $s;
		}
		return self::$settings;
	}

	/* --------------------------------------------------------------------
	 * Start
	 * ------------------------------------------------------------------ */

	public static function boot() {
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );

		// Vždy (i při vypnutém blokování): stránka nastavení a nástroje.
		add_action( 'init', array( __CLASS__, 'handle_post_type_support' ), PHP_INT_MAX );
		add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_hbk_close', array( __CLASS__, 'action_close' ) );
		add_action( 'admin_post_hbk_delete', array( __CLASS__, 'action_delete' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );

		$s = self::settings();
		if ( ! $s['enabled'] ) {
			return;
		}

		// Web: komentáře zavřené, existující skryté.
		add_filter( 'comments_open', array( __CLASS__, 'filter_open' ), 20, 2 );
		add_filter( 'pings_open', array( __CLASS__, 'filter_open' ), 20, 2 );
		add_filter( 'comments_array', array( __CLASS__, 'filter_comments_array' ), 20, 2 );
		add_filter( 'get_comments_number', array( __CLASS__, 'filter_comments_number' ), 20, 2 );
		add_filter( 'comments_template', array( __CLASS__, 'filter_comments_template' ), 20 );
		add_action( 'widgets_init', array( __CLASS__, 'unregister_widgets' ), 20 );
		foreach ( self::COMMENT_BLOCKS as $block ) {
			add_filter( 'render_block_' . $block, array( __CLASS__, 'filter_block' ), 20, 3 );
		}
		add_filter( 'render_block_core/latest-comments', '__return_empty_string', 20 );

		// Administrace.
		if ( $s['hide_admin'] ) {
			add_action( 'admin_menu', array( __CLASS__, 'remove_admin_menus' ), 999 );
			add_action( 'admin_init', array( __CLASS__, 'redirect_comment_screens' ) );
			add_action( 'admin_bar_menu', array( __CLASS__, 'remove_admin_bar_node' ), 999 );
			add_action( 'admin_head', array( __CLASS__, 'admin_css' ) );
		}

		// API, feedy, pingbacky.
		if ( $s['disable_api'] ) {
			add_filter( 'rest_pre_dispatch', array( __CLASS__, 'filter_rest' ), 10, 3 );
			add_filter( 'xmlrpc_methods', array( __CLASS__, 'filter_xmlrpc' ) );
			add_filter( 'wp_headers', array( __CLASS__, 'filter_headers' ) );
			add_filter( 'feed_links_show_comments_feed', '__return_false' );
			add_filter( 'feed_links_extra_show_post_comments_feed', '__return_false' );
			add_action( 'template_redirect', array( __CLASS__, 'block_comment_feeds' ), 1 );
		}
	}

	public static function activate() {
		add_option( self::OPTION, self::defaults() ); // Nic nepřepíše, pokud už existuje.
	}

	/* --------------------------------------------------------------------
	 * Výjimky a podpora typů obsahu
	 * ------------------------------------------------------------------ */

	private static function is_excluded( $post_id = null ) {
		$excluded = self::settings()['excluded_types'];
		if ( empty( $excluded ) ) {
			return false;
		}
		$type = get_post_type( $post_id ? $post_id : null );
		return $type && in_array( $type, $excluded, true );
	}

	public static function handle_post_type_support() {
		$s = self::settings();

		foreach ( get_post_types( array(), 'objects' ) as $type => $object ) {
			if ( ! post_type_supports( $type, 'comments' ) && ! post_type_supports( $type, 'trackbacks' ) ) {
				continue;
			}
			self::$commentable_types[ $type ] = $object->labels->name . ' (' . $type . ')';

			if ( $s['enabled'] && ! in_array( $type, $s['excluded_types'], true ) ) {
				// Zmizí panel Diskuze v editoru, sloupec komentářů ve výpisech i volba v rychlých/hromadných úpravách.
				remove_post_type_support( $type, 'comments' );
				remove_post_type_support( $type, 'trackbacks' );
			}
		}
	}

	/* --------------------------------------------------------------------
	 * Web
	 * ------------------------------------------------------------------ */

	public static function filter_open( $open, $post_id = null ) {
		return self::is_excluded( $post_id ) ? $open : false;
	}

	public static function filter_comments_array( $comments, $post_id = null ) {
		return self::is_excluded( $post_id ) ? $comments : array();
	}

	public static function filter_comments_number( $count, $post_id = null ) {
		return self::is_excluded( $post_id ) ? $count : 0;
	}

	public static function filter_comments_template( $file ) {
		return self::is_excluded() ? $file : __DIR__ . '/templates/empty-comments.php';
	}

	public static function filter_block( $content, $block = null, $instance = null ) {
		$post_id = ( $instance instanceof WP_Block && ! empty( $instance->context['postId'] ) )
			? (int) $instance->context['postId']
			: get_the_ID();

		return self::is_excluded( $post_id ) ? $content : '';
	}

	public static function unregister_widgets() {
		unregister_widget( 'WP_Widget_Recent_Comments' );
	}

	/* --------------------------------------------------------------------
	 * Administrace
	 * ------------------------------------------------------------------ */

	public static function remove_admin_menus() {
		remove_menu_page( 'edit-comments.php' );
		remove_submenu_page( 'options-general.php', 'options-discussion.php' );
	}

	public static function redirect_comment_screens() {
		global $pagenow;
		if ( in_array( $pagenow, array( 'edit-comments.php', 'comment.php', 'options-discussion.php' ), true ) ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
	}

	public static function remove_admin_bar_node( $wp_admin_bar ) {
		$wp_admin_bar->remove_node( 'comments' );
	}

	public static function admin_css() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->base, array( 'dashboard', 'profile', 'user-edit' ), true ) ) {
			return;
		}
		echo '<style id="hbk-admin-css">'
			. '#dashboard_right_now .comment-count,#dashboard_right_now .comment-mod-count,'
			. '#latest-comments,.user-comment-shortcuts-wrap{display:none!important}'
			. '</style>';
	}

	/* --------------------------------------------------------------------
	 * API, feedy, pingbacky
	 * ------------------------------------------------------------------ */

	public static function filter_rest( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}
		if ( 0 === strpos( $request->get_route(), '/wp/v2/comments' ) && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'hbk_comments_disabled',
				__( 'Komentáře jsou na tomto webu vypnuté.', 'hervyho-blokator-komentaru' ),
				array( 'status' => 403 )
			);
		}
		return $result;
	}

	public static function filter_xmlrpc( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'], $methods['wp.newComment'] );
		return $methods;
	}

	public static function filter_headers( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	public static function block_comment_feeds() {
		if ( ! is_comment_feed() ) {
			return;
		}
		if ( is_singular() && self::is_excluded( get_queried_object_id() ) ) {
			return;
		}
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}

	/* --------------------------------------------------------------------
	 * Čistka
	 * ------------------------------------------------------------------ */

	/** SQL podmínka vylučující typy obsahu ve výjimkách (NULL = komentář u smazaného příspěvku). */
	private static function type_exclusion_sql( $column ) {
		global $wpdb;
		$excluded = self::settings()['excluded_types'];
		if ( empty( $excluded ) ) {
			return '';
		}
		$placeholders = implode( ',', array_fill( 0, count( $excluded ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->prepare( " AND ( {$column} IS NULL OR {$column} NOT IN ( {$placeholders} ) )", $excluded );
	}

	private static function deletable_types_sql() {
		return "'" . implode( "','", self::DELETABLE_COMMENT_TYPES ) . "'";
	}

	private static function prepare_long_task() {
		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	private static function count_open_posts() {
		global $wpdb;
		$excl = self::type_exclusion_sql( 'post_type' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ( comment_status <> 'closed' OR ping_status <> 'closed' ){$excl}" );
	}

	private static function count_deletable_comments() {
		global $wpdb;
		$excl  = self::type_exclusion_sql( 'p.post_type' );
		$types = self::deletable_types_sql();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} c LEFT JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID WHERE c.comment_type IN ( {$types} ){$excl}" );
	}

	/** Uzavře komentáře a pingbacky u všech existujících obsahů (kromě výjimek) a nastaví výchozí hodnoty. */
	private static function close_all() {
		global $wpdb;
		self::prepare_long_task();

		$excl  = self::type_exclusion_sql( 'post_type' );
		$total = 0;

		while ( true ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ( comment_status <> 'closed' OR ping_status <> 'closed' ){$excl} LIMIT " . self::BATCH ) );
			if ( empty( $ids ) ) {
				break;
			}
			$in      = implode( ',', $ids );
			$updated = $wpdb->query( "UPDATE {$wpdb->posts} SET comment_status = 'closed', ping_status = 'closed' WHERE ID IN ( {$in} )" ); // phpcs:ignore
			if ( ! $updated ) {
				break; // Pojistka proti nekonečné smyčce při chybě DB.
			}
			foreach ( $ids as $id ) {
				clean_post_cache( $id );
			}
			$total += (int) $updated;
		}

		update_option( 'default_comment_status', 'closed' );
		update_option( 'default_ping_status', 'closed' );
		update_option( 'default_pingback_flag', '0' );

		return $total;
	}

	/** Trvale smaže běžné komentáře, pingbacky a trackbacky (kromě výjimek) včetně metadat a přepočítá počty. */
	private static function delete_all() {
		global $wpdb;
		self::prepare_long_task();

		$excl     = self::type_exclusion_sql( 'p.post_type' );
		$types    = self::deletable_types_sql();
		$total    = 0;
		$post_ids = array();

		while ( true ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = array_map( 'intval', $wpdb->get_col( "SELECT c.comment_ID FROM {$wpdb->comments} c LEFT JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID WHERE c.comment_type IN ( {$types} ){$excl} LIMIT " . self::BATCH ) );
			if ( empty( $ids ) ) {
				break;
			}
			$in = implode( ',', $ids );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$post_ids = array_merge( $post_ids, $wpdb->get_col( "SELECT DISTINCT comment_post_ID FROM {$wpdb->comments} WHERE comment_ID IN ( {$in} )" ) );
			$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ( {$in} )" );
			$deleted = $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_ID IN ( {$in} )" );
			// phpcs:enable

			if ( ! $deleted ) {
				break; // Pojistka proti nekonečné smyčce při chybě DB.
			}
			clean_comment_cache( $ids );
			$total += (int) $deleted;
		}

		// Přepočet počtu komentářů u dotčených příspěvků (core logika, smaže i cache příspěvku).
		foreach ( array_unique( array_map( 'intval', $post_ids ) ) as $post_id ) {
			if ( $post_id > 0 ) {
				wp_update_comment_count_now( $post_id );
			}
		}

		// Osiřelá metadata (komentář už neexistuje) – bezpečné smazat vždy.
		$wpdb->query( "DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL" ); // phpcs:ignore

		wp_cache_delete( 'comments-0', 'counts' );

		return $total;
	}

	private static function redirect_back( $done, $count ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::SLUG,
					'hbk_done'  => $done,
					'hbk_count' => (int) $count,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Na tuto akci nemáte oprávnění.', 'hervyho-blokator-komentaru' ), 403 );
		}
		check_admin_referer( $action );
	}

	public static function action_close() {
		self::guard( 'hbk_close' );
		self::redirect_back( 'closed', self::close_all() );
	}

	public static function action_delete() {
		self::guard( 'hbk_delete' );
		if ( empty( $_POST['hbk_confirm'] ) ) {
			self::redirect_back( 'not_confirmed', 0 );
		}
		self::redirect_back( 'deleted', self::delete_all() );
	}

	/* --------------------------------------------------------------------
	 * Stránka nastavení
	 * ------------------------------------------------------------------ */

	public static function register_settings_page() {
		add_options_page(
			__( 'Hervyho blokátor komentářů', 'hervyho-blokator-komentaru' ),
			__( 'Blokátor komentářů', 'hervyho-blokator-komentaru' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$allowed = array_keys( self::$commentable_types );
		$types   = isset( $input['excluded_types'] ) ? array_map( 'sanitize_key', (array) $input['excluded_types'] ) : array();

		return array(
			'enabled'        => (int) ! empty( $input['enabled'] ),
			'hide_admin'     => (int) ! empty( $input['hide_admin'] ),
			'disable_api'    => (int) ! empty( $input['disable_api'] ),
			'excluded_types' => array_values( array_intersect( $types, $allowed ) ),
		);
	}

	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Nastavení', 'hervyho-blokator-komentaru' ) . '</a>' );
		return $links;
	}

	private static function render_notice() {
		if ( empty( $_GET['hbk_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$done  = sanitize_key( wp_unslash( $_GET['hbk_done'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$count = isset( $_GET['hbk_count'] ) ? absint( $_GET['hbk_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'closed' === $done ) {
			/* translators: %d: počet obsahů */
			$msg  = sprintf( __( 'Komentáře byly uzavřeny u %d obsahů. Výchozí nastavení pro nové obsahy je nyní „zavřeno“.', 'hervyho-blokator-komentaru' ), $count );
			$type = 'success';
		} elseif ( 'deleted' === $done ) {
			/* translators: %d: počet komentářů */
			$msg  = sprintf( __( 'Trvale smazáno %d komentářů, pingbacků a trackbacků.', 'hervyho-blokator-komentaru' ), $count );
			$type = 'success';
		} elseif ( 'not_confirmed' === $done ) {
			$msg  = __( 'Mazání neproběhlo – nezaškrtli jste potvrzení.', 'hervyho-blokator-komentaru' );
			$type = 'warning';
		} else {
			return;
		}
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $msg ) );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s             = self::settings();
		$types         = self::$commentable_types;
		$open_posts    = self::count_open_posts();
		$comment_count = self::count_deletable_comments();
		$name          = self::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Hervyho blokátor komentářů', 'hervyho-blokator-komentaru' ); ?></h1>
			<?php self::render_notice(); ?>

			<?php if ( class_exists( 'WooCommerce' ) && isset( $types['product'] ) && ! in_array( 'product', $s['excluded_types'], true ) ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'Je aktivní WooCommerce. Pokud chcete zachovat recenze produktů, zaškrtněte níže výjimku pro „Produkty (product)“.', 'hervyho-blokator-komentaru' ); ?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Blokování', 'hervyho-blokator-komentaru' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?>>
								<?php esc_html_e( 'Vypnout komentáře, pingbacky a trackbacky na celém webu', 'hervyho-blokator-komentaru' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Administrace', 'hervyho-blokator-komentaru' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[hide_admin]" value="1" <?php checked( $s['hide_admin'], 1 ); ?>>
								<?php esc_html_e( 'Skrýt menu Komentáře, Nastavení → Diskuze, ikonu v horní liště a komentáře na nástěnce', 'hervyho-blokator-komentaru' ); ?></label>
							<p class="description"><?php esc_html_e( 'Pokud používáte výjimky a potřebujete je moderovat přes obrazovku Komentáře, tuto volbu vypněte.', 'hervyho-blokator-komentaru' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'API a feedy', 'hervyho-blokator-komentaru' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[disable_api]" value="1" <?php checked( $s['disable_api'], 1 ); ?>>
								<?php esc_html_e( 'Zablokovat REST API komentářů pro nepřihlášené, pingbacky přes XML-RPC a RSS feedy komentářů', 'hervyho-blokator-komentaru' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Výjimky', 'hervyho-blokator-komentaru' ); ?></th>
						<td>
							<?php if ( empty( $types ) ) : ?>
								<em><?php esc_html_e( 'Žádný typ obsahu nepodporuje komentáře.', 'hervyho-blokator-komentaru' ); ?></em>
							<?php else : ?>
								<fieldset>
									<?php foreach ( $types as $slug => $label ) : ?>
										<label style="display:block;margin-bottom:4px">
											<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[excluded_types][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $s['excluded_types'], true ) ); ?>>
											<?php echo esc_html( $label ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'U zaškrtnutých typů obsahu zůstanou komentáře zachované a čistka se jich nedotkne.', 'hervyho-blokator-komentaru' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Čistka', 'hervyho-blokator-komentaru' ); ?></h2>
			<p><?php esc_html_e( 'Nástroje respektují výjimky výše. Před mazáním si zálohujte databázi.', 'hervyho-blokator-komentaru' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Uzavřít komentáře', 'hervyho-blokator-komentaru' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="hbk_close">
							<?php wp_nonce_field( 'hbk_close' ); ?>
							<p>
								<?php
								/* translators: %d: počet obsahů */
								echo esc_html( sprintf( __( 'Obsahů s otevřenými komentáři nebo pingbacky: %d', 'hervyho-blokator-komentaru' ), $open_posts ) );
								?>
							</p>
							<p class="description"><?php esc_html_e( 'Nastaví u všech existujících příspěvků, stránek atd. komentáře a pingbacky na „zavřeno“ a změní i výchozí nastavení pro nové obsahy. Díky tomu zůstanou komentáře zavřené i po deaktivaci pluginu.', 'hervyho-blokator-komentaru' ); ?></p>
							<?php submit_button( __( 'Uzavřít komentáře u všech obsahů', 'hervyho-blokator-komentaru' ), 'secondary', 'submit', false, $open_posts ? array() : array( 'disabled' => 'disabled' ) ); ?>
						</form>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Smazat komentáře', 'hervyho-blokator-komentaru' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
							onsubmit="return confirm('<?php echo esc_js( __( 'Opravdu trvale smazat všechny komentáře? Tuto akci nelze vrátit.', 'hervyho-blokator-komentaru' ) ); ?>');">
							<input type="hidden" name="action" value="hbk_delete">
							<?php wp_nonce_field( 'hbk_delete' ); ?>
							<p>
								<?php
								/* translators: %d: počet komentářů */
								echo esc_html( sprintf( __( 'Komentářů, pingbacků a trackbacků ke smazání: %d (včetně spamu a koše)', 'hervyho-blokator-komentaru' ), $comment_count ) );
								?>
							</p>
							<p class="description"><?php esc_html_e( 'Trvale smaže komentáře včetně metadat a přepočítá počty komentářů. Jiné typy záznamů v tabulce komentářů (např. recenze a poznámky k objednávkám WooCommerce) zůstanou zachovány.', 'hervyho-blokator-komentaru' ); ?></p>
							<p><label><input type="checkbox" name="hbk_confirm" value="1" required>
								<?php esc_html_e( 'Mám zálohu a rozumím, že smazání je nevratné.', 'hervyho-blokator-komentaru' ); ?></label></p>
							<?php submit_button( __( 'Trvale smazat komentáře', 'hervyho-blokator-komentaru' ), 'delete', 'submit', false, $comment_count ? array() : array( 'disabled' => 'disabled' ) ); ?>
						</form>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}
}

Hervy_Comment_Blocker::boot();
