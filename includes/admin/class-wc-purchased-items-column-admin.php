<?php
/**
 * WooCommerce Purchased Items Column Admin
 *
 * @class WC_Purchased_Items_Column_Admin
 * @package WC_Purchased_Items_Column
 * @subpackage WC_Purchased_Items_Column/Admin
 * @version 1.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WC_Purchased_Items_Column_Admin' ) ) {
	return new WC_Purchased_Items_Column_Admin();
}

/**
 * WC_Purchased_Items_Column_Admin class.
 */
class WC_Purchased_Items_Column_Admin {
	/**
	 * Constructor.
	 */
	public function __construct() {
		// Enqueue scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

		add_filter( 'manage_shop_order_posts_columns', array( $this, 'add_purchased_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'show_purchased_items_in_column' ), 10, 2 );

		add_action( 'wp_ajax_wc_purchased_items_column_fetch_items_ajax', array( $this, 'ajax_callback' ) );
	}

	/**
	 * Valid screen ids for plugin scripts & styles
	 *
	 * @return  bool
	 */
	public function is_valid_screen() {
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';
		return 'edit-shop_order' === $screen_id;
	}

	/**
	 * Enqueue styles.
	 */
	public function admin_styles() {
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		// Register admin styles.
		wp_register_style( 'wc-purchased-items-column-admin-styles', wc_purchased_items_column()->plugin_url() . '/assets/css/admin' . $suffix . '.css', array(), WC_PURCHASED_ITEMS_COLUMN_VERSION );

		// Admin styles for wc_purchased_items_column pages only.
		if ( $this->is_valid_screen() ) {
			wp_enqueue_style( 'wc-purchased-items-column-admin-styles' );
		}
	}

	/**
	 * Enqueue scripts.
	 */
	public function admin_scripts() {
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		// Register scripts.
		wp_register_script( 'wc-purchased-items-column-admin', wc_purchased_items_column()->plugin_url() . '/assets/js/admin' . $suffix . '.js', array( 'jquery' ), WC_PURCHASED_ITEMS_COLUMN_VERSION, true );

		// Admin scripts for wc_purchased_items_column pages only.
		if ( $this->is_valid_screen() ) {
			wp_enqueue_script( 'wc-purchased-items-column-admin' );
			$params = array(
				/* translators: %s Order number */
				'i18n_order_items'          => __( 'Order Items: %s', 'wc-purchased-items-column' ),
				'i18n_something_went_wrong' => __( 'Sorry, something went wrong. Please try again, or refresh the page.', 'wc-purchased-items-column' ),
				'ajax_url'                  => admin_url( 'admin-ajax.php' ),
				'nonce'                     => wp_create_nonce( 'wc-purchased-items-column-fetch-items' ),
			);
			wp_localize_script( 'wc-purchased-items-column-admin', 'wc_purchased_items_column_params', $params );
		}
	}

	/**
	 * Add purchased column
	 *
	 * @param array $columns Columns array.
	 * @return array
	 */
	public function add_purchased_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $name ) {
			$new_columns[ $key ] = $name;
			if ( 'order_number' === $key ) {
				$new_columns['order_items'] = __( 'Purchased', 'wc-purchased-items-column' );
			}
		}

		return $new_columns;
	}

	/**
	 * Output purchased items
	 *
	 * @param string $column The name of the column to display.
	 * @param int    $post_id The current post ID.
	 */
	public function show_purchased_items_in_column( $column, $post_id ) {
		if ( 'order_items' === $column ) {
			$order = wc_get_order( $post_id );
			if ( ! $order ) {
				return;
			}

			$items_count = $order->get_item_count();

			$label = sprintf(
				/* translators: %s Item count */
				_n( '%d Item', '%d Items', $items_count, 'wc-purchased-items-column' ),
				$items_count
			);
			?>
			<button type="button" class="button wc-show-order-items no-link" data-id="<?php echo esc_attr( $order->get_id() ); ?>" data-count="<?php echo esc_attr( $items_count ); ?>" title="<?php esc_html_e( 'Click to view items', 'wc-purchased-items-column' ); ?>">
				<?php echo esc_html( $label ); ?>
			</button>
			<?php
		}
	}

	/**
	 * Ajax callback to display order items
	 *
	 * @throws Exception When validations fails.
	 */
	public function ajax_callback() {
		try {
			// Check nonce is passed or not?
			if ( ! isset( $_POST['nonce'] ) ) {
				throw new Exception( __( 'Sorry, we can\'t process this request without nonce. Please try again, or refresh the page.', 'wc-purchased-items-column' ) );
			}

			// Verify the passed nonce value.
			if ( ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'wc-purchased-items-column-fetch-items' ) ) {
				throw new Exception( __( 'Sorry, security validation failed. Please try again, or refresh the page.', 'wc-purchased-items-column' ) );
			}

			$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
			if ( empty( $order_id ) ) {
				throw new Exception( __( 'Sorry, we can\'t process your request without Order ID.', 'wc-purchased-items-column' ) );
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				throw new Exception( sprintf( /* translators: %s Order ID */ __( 'Sorry, we didn\'t found any order with Order ID #%s.', 'wc-purchased-items-column' ), esc_html( $order_id ) ) );
			}

			ob_start();
			if ( count( $order->get_items() ) > 0 ) {
				foreach ( $order->get_items() as $item ) {
					$product          = apply_filters( 'woocommerce_order_item_product', $item->get_product(), $item );
					$product_edit_url = $product ? esc_url( get_edit_post_link( $product->get_id() ) ) : 'javascript:void(0)';
					?>
					<section class="wc-order-items-card">
						<?php if ( $product ) : ?>
						<span class="wc-order-items-card-icon" aria-hidden="true">
							<div class="wc-product-image-overlay">
								<div class="wc-product-image"><?php echo $product->get_image( array( 60, 60 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
							</div>
						</span>
						<?php endif; ?>
						<header class="wc-order-items-card-header">
							<h4 class="wc-order-items-card-title"><a href="<?php echo esc_attr( $product_edit_url ); ?>" target="_blank"><?php echo esc_html( apply_filters( 'woocommerce_order_item_name', $item['name'], $item, false ) ); ?></a></h4>
						</header>
						<div class="wc-order-items-card-body">
							<span class="wc-order-items-card-qty"><?php esc_html_e( 'Qty', 'wc-purchased-items-column' ); ?>: <?php echo absint( $item['qty'] ); ?></span>
						</div>
					</section>
					<?php
				}
			}
			$content = ob_get_clean();

			// If schedule get success then send the success response.
			wp_send_json_success(
				array(
					'items' => $content,
				)
			);
		} catch ( Exception $e ) {
			if ( $e->getMessage() ) {
				wp_send_json_error( array( 'error' => $e->getMessage() ) );
			}
		}
	}
}

return new WC_Purchased_Items_Column_Admin();
