<?php
/**
 * @wordpress-plugin
 *
 * Plugin Name:       KOPA QR
 * Description:       KÖPA allows you to add a QR code for automatic payment to WooCommerce products.
 * Version:           1.0.3
 * Requires PHP:      7.0
 * Requires at least: 5.0
 * Author:            Tehnološko Partnerstvo
 * Author URI:        kopa.rs
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       kopa-qr
 * Domain Path:       /languages
 * Contributors:      tehnoloskopartnerstvo, ivijanstefan
 * Developed By:      Ivijan-Stefan Stipic <infinitumform@gmail.com>
 */

// If someone try to called this file directly via URL, abort.
if ( ! defined( 'WPINC' ) ) { die( "Don't mess with us." ); }
if ( ! defined( 'ABSPATH' ) ) { exit; }

if( !class_exists('Kopa_QR') ) : class Kopa_QR {
	// JS Version
	const JS_VERSION = '1.0.0';
	
	// CSS Version
	const CSS_VERSION = '1.0.0';
	
	// Textdomain
	const DOMAIN = 'kopa-qr';
	
	// QR Indentifier
	const QR_ID = '[RQRC]';
	
	// Default position
    const DEFAULT_POSITION = '10';
	
	// QR code max size
    const DEFAULT_MAX_SIZE = '200px';
	
	// Current position
    public $position = '';
	
	// Available positions
    private $positions;

	// Construct
    private function __construct()
    {
        $this->position = get_option('woocommerce_kopa_custom_img_position', self::DEFAULT_POSITION);
		
        // Check if WooCommerce is active
        if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))))
        {
            add_action('admin_notices', array(
                $this,
                'woo_kopa_qr_for_woocommerce_missing_wc_notice'
            ));
            return;
        }
		
		add_action('wp', function() {
			if( function_exists('get_the_ID') && get_the_ID() ) {
				if( NULL !== ($_custom_kopa_qr_position = get_post_meta(get_the_ID(), '_custom_kopa_qr_position', true)) ) {
					$this->position = $_custom_kopa_qr_position;
				}
			}
			add_action('woocommerce_single_product_summary', array(
				$this,
				'display_kopa_qr_code'
			), $this->position);
			
			add_filter('woocommerce_get_price_html', array(
				$this,
				'custom_variable_price_html'
			), $this->position, 2);
		});

        add_action('add_meta_boxes', array(
            $this,
            'add_meta_box'
        ));
        add_action('save_post', array(
            $this,
            'save_meta_box'
        ), 1, 3);
		add_action('dokan_new_product_added', array(
            $this,
            'dokan_save_meta_box'
        ), 1, 2);
		add_action('dokan_product_updated', array(
            $this,
            'dokan_save_meta_box'
        ), 1, 2);
		add_action('woocommerce_save_product_variation', array(
            $this,
            'save_meta_box_variation'
        ), 1, 2);
        add_action('plugins_loaded', array(
            $this,
            'load_textdomain'
        ));
        add_action('woocommerce_product_after_variable_attributes', array(
            $this,
            'add_custom_qr_code_to_variations'
        ), 10, 3);
        add_filter('woocommerce_general_settings', array(
            $this,
            'add_default_qr_code_positions'
        ));
		add_action('woocommerce_before_shop_loop_item', array(
			$this,
			'shop_loop_item'
		), 1, 1);
		add_action('wp_enqueue_scripts', array(
			$this,
			'register_scripts'
		), 1, 1);
		add_filter( 'woocommerce_show_variation_price', array(
			$this,
			'show_variation_price'
		), 10, 3 );
		add_filter( 'woocommerce_available_variation', array(
			$this,
			'set_available_variation_custom_field'
		), 10, 3 );
		add_action( 'dokan_product_edit_after_product_tags', array(
			$this,
			'show_on_dokan_edit_page'
		), 99, 2 );
		add_action( 'wp_ajax_kopa_qr_dokan_variants', array(
			$this,
			'ajax__kopa_qr_dokan_variants'
		), 10 );
		add_action( 'wp_ajax_nopriv_kopa_qr_dokan_variants', array(
			$this,
			'ajax__kopa_qr_dokan_variants'
		), 10 );
	}
	
	/*
	 * Define custom positions
	 */
	public function positions() {
		if (!$this->positions)
        {
            $this->positions = apply_filters( 'kopa-qr-positions', array(
                '0' => __('Above the title', 'kopa-qr') ,
                '10' => __('Below the title', 'kopa-qr') ,
                '15' => __('Below the variations', 'kopa-qr') ,
                '35' => __('Below Add to Cart Button', 'kopa-qr')
            ), $this );
        }
		
		return $this->positions;
	}
	
	/*
	 * Get the QR code size
	 */
	public function get_qr_code_size() {
		static $size;
		
		if( !$size ) {
			// Make it safe
			$size = get_option('woocommerce_kopa_max_width', self::DEFAULT_MAX_SIZE);
			if( !preg_match('/([0-9.]+)\s?(px|\%|em|ex|ch|rem|vw|vmin|vmax|pc|pt|in|mm|cm)/i', $size) ) {
				$size = self::DEFAULT_MAX_SIZE;
			}
		}
		
		return $size;
	}
	
	/*
	 * Register QR code variation template
	 */
	public function set_available_variation_custom_field( $variation_data, $product, $variation ) {
		return array_merge( $variation_data, array(
			'kopa_qr_code' => $this->wp_qr_code_template($variation->get_id(), $product->get_id())
		) );
	}
	
	/*
	 * Register scripts
	 */
    public function register_scripts($attr)
    {
		wp_register_script(
			'kopa-qr', 
			esc_url( plugin_dir_url( __FILE__ ) .'assets/js/kopa-qr.js' ),
			array ('jquery'),
			self::JS_VERSION,
			true
		);
		
		wp_localize_script( 'kopa-qr', 'kopa_qr', 
			[
				'ajaxurl' => admin_url('/admin-ajax.php'),
				'label' => [
					'copy_to_clipboard' => esc_html__('Copied to clipboard!', 'kopa-qr'),
					'qr_code' => esc_attr('KÖPA QR', 'kopa-qr')
				]
			]
		);
		
		wp_register_style(
			'kopa-qr', 
			esc_url( plugin_dir_url( __FILE__ ) .'assets/css/kopa-qr.css' ),
			'all',
			self::CSS_VERSION
		);
	}
	
	/*
	 * Show variation price when QR code is available
	 */
	public function show_variation_price ( $bool, $product, $variation ) {
		return ( $this->qr_code_data( $variation->get_id() ) !== NULL );
	}
	
	/*
	 * Get QR code data informations
	 */
	protected $__qr_code_data = [];
	public function qr_code_data ( int $product_id ) {
	
		if( !( $this->__qr_code_data[$product_id] ?? NULL ) ) {
			
			$kopa_qr_code_link = get_post_meta($product_id, 'kopa_qr_code_link', true);
			$kopa_deep_link = get_post_meta($product_id, 'kopa_deep_link', true);
			
			if( $kopa_qr_code_link && $kopa_deep_link ) {
				$this->__qr_code_data[$product_id] = [
					'link' => $kopa_deep_link,
					'image' => $kopa_qr_code_link,
					'code' => get_post_meta($product_id, 'kopa_qr_code', true)
				];
			}
			
		}
		
		return apply_filters( 'kopa-qr-code-data', ( $this->__qr_code_data[$product_id] ?? NULL ), $product_id, $this );
	}
	
	/*
	 * Loop shop images
	 */
    public function shop_loop_item($attr)
    {
		global $product;
		
		$kopa_id = $product->get_id();
		
		if( $variations = $product->get_children() ) {
			$kopa_id = array_shift( $variations );
		}
		
		if( $data = $this->qr_code_data( $kopa_id ) ) {
			wp_enqueue_script( 'kopa-qr' );
			wp_enqueue_style( 'kopa-qr' );
			
			printf(
				apply_filters(
					'kopa-qr-shop-loop-item',
					'<a href="%3$s" class="woocommerce-loop-product-kopa-qr"><img src="%1$s" alt="%2$s" /></a><div class="woocommerce-loop-product-kopa-qr-container"><button type="button" class="kopa-qr-close" tabindex="0">&times;</button><a href="%3$s" class="woocommerce-loop-product-kopa-qr-link"><img src="%4$s" alt="%5$s" /></a></div>',
					$kopa_id,
					$data,
					$product
				),
				esc_url( $this->kopa_icon() ),
				'', // Alt of the icon
				esc_url( $data['link'] ),
				esc_url( $data['image'] ),
				'', // Alt of QR code
			);
		}
	}
	
	
	/*
	 * Get copa icon
	 */
    public function kopa_icon()
    {
		$plugin_dir_url = plugin_dir_url( __FILE__ );
		$plugin_dir_path = plugin_dir_path( __FILE__ );
		$stylesheet_directory = get_stylesheet_directory( );
		$stylesheet_directory_uri = get_stylesheet_directory_uri( );
		
		// Default plugin icon
		$icon = $default = $plugin_dir_url . 'assets/images/kopa-icon.png';
		
		// Find into theme icon
		if( file_exists($stylesheet_directory . '/kopa-qr/images/kopa-icon.png') ) {
			$icon = $stylesheet_directory_uri . '/kopa-qr/images/kopa-icon.png';
		}
		
		// Find icon by locale
		$locale = NULL;
		if( is_user_logged_in() ) {
			$locale = get_user_locale( get_current_user_id() );
		}
		
		if( !$locale ) {
			$locale = get_locale();
		}
		
		if( $locale && file_exists($plugin_dir_path . '/assets/images/kopa-icon-' . $locale . '.png') ) {
			$icon = $plugin_dir_url . '/assets/images/kopa-icon-' . $locale . '.png';
		}
		
		if( $locale && file_exists($stylesheet_directory . '/kopa-qr/images/kopa-icon-' . $locale . '.png') ) {
			$icon = $stylesheet_directory_uri . '/kopa-qr/images/kopa-icon-' . $locale . '.png';
		}
		
		// Apply filters
		return apply_filters('kopa-icon', $icon, $locale, $default);
	}
	
	/*
	 * Check is WooCommerce exists
	 */
    public function woo_kopa_qr_for_woocommerce_missing_wc_notice()
    {
        printf('<div class="error"><p>%s</p></div>', sprintf(__('The %1$s requires %2$s to be active.', 'kopa-qr') , '<strong>' . esc_html__('KÖPA QR extension', 'kopa-qr') . '</strong>', '<strong>' . esc_html__('WooCommerce', 'kopa-qr') . '</strong>'));
    }

	
	/*
	 * QR Code for the admin templates
	 */
	public function wp_admin_qr_code_template(int $post_id, $label = true, $variation = false) {
		if( empty($post_id) ) {
			return '';
		}
		
		$html = '';
		
		add_action('admin_footer', [$this, 'copy_to_clipboard'], 99, 0);
		
		$data = $this->qr_code_data( $post_id );
		
		if( $data ) :
		ob_start(); ?>
<div class="kopa-qr-code-container">
	<?php if( $label ) : ?>
	<b><label for="kopa-qr-code-image"><?php esc_html_e('KÖPA QR', 'kopa-qr'); ?></label></b>
	<br>
	<?php endif; ?>
	<div class="thickbox kopa-qr-code">
		<a href="<?php echo esc_url($data['link']); ?>" style="display:block !important;" class="kopa-qr-code-link">
			<img src="<?php echo esc_url($data['image']); ?>" alt="<?php esc_attr_e('KÖPA QR', 'kopa-qr'); ?>" id="kopa-qr-code-image" style="display:block;width:100%;max-width:<?php echo esc_attr($variation ? 200 : 400); ?>px;height:auto;" />
		</a>
	</div>
	<br>
	<div class="ajaxtag hide-if-no-js kopa-qr-copy-deep-link-container">
		<label class="screen-reader-text" for="kopa-qr-deep-link"><?php esc_attr_e('Copy KÖPA QR Deep Link', 'kopa-qr'); ?></label>
		<input type="text" id="kopa-qr-deep-link" class="form-input-tip ui-autocomplete-input kopa-qr-copy-deep-link-input" size="16" autocomplete="off" value="<?php echo esc_url($data['link']); ?>" role="combobox" readonly="true">
		<input type="button" class="button kopa-qr-copy-deep-link" value="<?php esc_attr_e('Copy', 'kopa-qr'); ?>">
	</div>
</div>
	<?php
			$html = ob_get_clean();
		endif;
		
		return apply_filters('kopa-qr-admin-template', $html, $post_id, $data, $label);
	}
	
	/*
	 * QR Code frontend template
	 */
	public function wp_qr_code_template(int $post_id, int $main_product_id = NULL) {
		if( empty($post_id) ) {
			return '';
		}
		
		if( !$main_product_id ) {
			$main_product_id = $post_id;
		}
		
		if( !$display_kopa_qr_code = get_post_meta($main_product_id, '_display_kopa_qr_code', true) ) {
			$display_kopa_qr_code = 'yes';
		}
		
		if($display_kopa_qr_code !== 'yes') {
			return '';
		}
		
		$html = '';
		
		$data = $this->qr_code_data($post_id);

		if( $data ) {			
			$html = wp_kses_post(
				'<p class="kopa-qr-code"><a href="' . esc_url($data['link']) . '" class="kopa-qr-code-link"><img decoding="async" src="' . esc_url($data['image']) . '" alt="' . esc_attr__('KÖPA QR', 'kopa-qr') . '" id="kopa-qr-code-image-' . esc_attr($post_id) . '" style="max-width: ' . esc_attr($this->get_qr_code_size()) . '" /></a></p>'
			);
		}
		
		return apply_filters(
			'display-kopa-qr-code',
			$html,
			$data,
			$post_id,
			$main_product_id,
			$this
		);
	}
	
	/*
	 * Copy to clipboard JS
	 */
	public function copy_to_clipboard() {
	ob_start(); ?>
<script id="kopa-qr-admin-copy-to-clipboard-script">
/* <![CDATA[ */
(function($){	
	$(document).on('click', '.kopa-qr-copy-deep-link', function(e){
		e.preventDefault();
		
		$('.kopa-qr-copied').remove();
		
		var $this = $(this)
			container = $this.closest('.kopa-qr-copy-deep-link-container'),
			input = container.find('.kopa-qr-copy-deep-link-input');

		input.select();
		document.execCommand('copy');
		input.blur();
		document.getSelection().removeAllRanges();
		
		container.append('<div class="kopa-qr-copied"><?php esc_html_e('Copied to clipboard!', 'kopa-qr'); ?></div>');
		setTimeout(function(){
			$('.kopa-qr-copied').remove();
		}, 1500);
	});
}(jQuery||window.jQuery));
/* ]]> */
</script>
	<?php
		$html = ob_get_clean();
		echo apply_filters('kopa-qr-admin-copy-to-clipboard-script', $html);
	}

    /*
	 * Display QR code to the variable product
	 */
    public function custom_variable_price_html($price, $product)
    {

        $variable_product_id = $product->get_id();
		$main_product_id = $product->get_parent_id();

		$post = get_post($variable_product_id);
		if ($post)
		{
			$type = $post->post_type;
			if ($type == 'product_variation')
			{
				if( $template = $this->wp_qr_code_template($variable_product_id, $main_product_id) ) {
					$price .=  $template;
				}
			} else if( is_a($product, 'WC_Product_Variable') ) {
				// Void for now
			}
		}

        return $price;
    }
	
	/*
	 * Display QR code to the single product
	 */
    public function display_kopa_qr_code()
    {
        global $post;

        $product = wc_get_product($post);
        if ( $product->is_type('variable') )
        {
			/*
			$variations = $product->get_children();
			$variation_id = array_shift( $variations );
			
			if( $template = $this->wp_qr_code_template( $variation_id, $product->get_id() ) ) {
				echo  wp_kses_post( $template );
			}
			*/
        }
        else
        {
			
			if( $template = $this->wp_qr_code_template( $product->get_id() ) ) {
				echo wp_kses_post( $template );
			}
        }

    }
	
	/*
	 * Display QR code on the Dokan page 
	 */
	function show_on_dokan_edit_page($post, $post_id) {
		$product = wc_get_product($post);
		
		wp_enqueue_script( 'kopa-qr' );
		wp_enqueue_style( 'kopa-qr' );
		add_action('wp_footer', [$this, 'copy_to_clipboard'], 99, 0);
		
		if( $template = $this->wp_qr_code_template( $product->get_id() ) ) {
			
			$data = $this->qr_code_data($post_id);
			
			ob_start(); ?>
<style>
.kopa-qr-copy-deep-link-container > .kopa-qr-copy-deep-link-input{
	max-width: 400px;
	display: inline-block !important;
}
.kopa-qr-copy-deep-link-container > .kopa-qr-copy-deep-link {
	display: inline-block !important;
}
</style>
<div class="dokan-form-group kopa-qr-copy-deep-link-container">
	<label class="screen-reader-text" for="kopa-qr-deep-link"><?php esc_attr_e('Copy KÖPA QR Deep Link', 'kopa-qr'); ?></label>
	<input type="text" id="kopa-qr-deep-link" class="dokan-form-control kopa-qr-copy-deep-link-input" size="16" autocomplete="off" value="<?php echo esc_url($data['link']); ?>" role="combobox" readonly="true">
	<input type="button" class="button kopa-qr-copy-deep-link" value="<?php esc_attr_e('Copy', 'kopa-qr'); ?>">
</div>
			<?php
			$input = ob_get_clean();
			printf(
				'<div class="dokan-form-group"><strong class="form-label">%1$s</strong>%2$s</div>%3$s', esc_html__('KÖPA QR', 'kopa-qr'),
				wp_kses_post( $template ),
				wp_kses(
					$input,
					array_merge( wp_kses_allowed_html('post'), [
						'input' => [
							'id' => true,
							'class' => true,
							'type' => true,
							'value' => true,
							'name' => true,
							'data-wp-taxonomy' => true,
							'autocomplete' => true,
							'aria-expanded' => true,
							'role' => true,
							'readonly' => true,
							'disabled' => true,
							'style' => true
						],
						'label' => [
							'id' => true,
							'class' => true,
							'for' => true
						],
						'style' => [
							'id' => true
						]
					])
				)
			);
		}
	}

	public function ajax__kopa_qr_dokan_variants() {
		if( $product_id = absint( sanitize_text_field($_POST['product_id'] ?? NULL) ) ) {
			if( $template = $this->wp_qr_code_template( $product_id ) ) {
				
				$data = $this->qr_code_data($product_id);
				
				ob_start(); ?>
<style>
.kopa-qr-copy-deep-link-container > .kopa-qr-copy-deep-link-input{
	max-width: 400px;
	display: inline-block !important;
}
.kopa-qr-copy-deep-link-container > .kopa-qr-copy-deep-link {
	display: inline-block !important;
}
</style>
<div class="dokan-form-group kopa-qr-copy-deep-link-container">
	<label class="screen-reader-text" for="kopa-qr-deep-link"><?php esc_attr_e('Copy KÖPA QR Deep Link', 'kopa-qr'); ?></label>
	<input type="text" id="kopa-qr-deep-link" class="dokan-form-control kopa-qr-copy-deep-link-input" size="16" autocomplete="off" value="<?php echo esc_url($data['link']); ?>" role="combobox" readonly="true">
	<input type="button" class="button kopa-qr-copy-deep-link" value="<?php esc_attr_e('Copy', 'kopa-qr'); ?>">
</div>
				<?php
				$input = ob_get_clean();
				printf(
					'<div class="dokan-form-group"><strong class="form-label">%1$s</strong>%2$s</div>%3$s', esc_html__('KÖPA QR', 'kopa-qr'),
					wp_kses_post( $template ),
					wp_kses(
						$input,
						array_merge( wp_kses_allowed_html('post'), [
							'input' => [
								'id' => true,
								'class' => true,
								'type' => true,
								'value' => true,
								'name' => true,
								'data-wp-taxonomy' => true,
								'autocomplete' => true,
								'aria-expanded' => true,
								'role' => true,
								'readonly' => true,
								'disabled' => true,
								'style' => true
							],
							'label' => [
								'id' => true,
								'class' => true,
								'for' => true
							],
							'style' => [
								'id' => true
							]
						])
					)
				);
			}
		}
	}
	
	/*
	 * Add default QR code possitions
	 */
    public function add_default_qr_code_positions($settings)
    {
		$new_settings = array(
			array(
				'title' => __('KÖPA QR', 'kopa-qr'),
				'desc' => __('General settings of the KÖPA QR plugin.', 'kopa-qr'),
				'type' => 'title',
				'id' => 'kopa_qr_wc',
			),
			array(
				'title' => __('KÖPA QR position', 'kopa-qr'),
				'desc' => __('Choose where you want the KÖPA QR code to appear', 'kopa-qr'),
				'id' => 'woocommerce_kopa_custom_img_position',
				'default' => self::DEFAULT_POSITION,
				'type' => 'select',
				'options' => $this->positions(),
				'desc_tip' => true
			),
			array(
				'title' => __('KÖPA QR max width', 'kopa-qr'),
				'desc' => __('Set the maximum width of KÖPA QR code in single products.', 'kopa-qr'),
				'id' => 'woocommerce_kopa_max_width',
				'default' => self::DEFAULT_MAX_SIZE,
				'type' => 'text',
				'desc_tip' => true
			),
			array(
				'type' => 'sectionend',
				'id'   => 'kopa_qr_wc',
			)
		);
		
        return array_merge( array_slice( $settings, 0, 7 ), $new_settings, array_slice( $settings, 7 ) );
    }

    /*
	 * function for showing image from variation instead input filed and url in admin panel for variation
	 */
    public function add_custom_qr_code_to_variations($loop, $variation_data, $variation)
    {
        if( $template = $this->wp_admin_qr_code_template($variation->ID, false, true) ) {
			echo wp_kses( $template, array_merge(wp_kses_allowed_html('post'), [
				'input' => [
					'id' => true,
					'class' => true,
					'type' => true,
					'value' => true,
					'name' => true,
					'data-wp-taxonomy' => true,
					'autocomplete' => true,
					'aria-expanded' => true,
					'role' => true,
					'readonly' => true,
					'disabled' => true
				],
				'label' => [
					'id' => true,
					'class' => true,
					'for' => true
				]
			]) );
		}
    }

	/*
	 * Render metaboxes
	 */
    public function render_meta_box($post)
    {
        wp_nonce_field(basename(__FILE__) , 'product_image_extension_nonce');
		
		$_custom_kopa_qr_position = get_post_meta($post->ID, '_custom_kopa_qr_position', true);
        if( NULL === ($_custom_kopa_qr_position) || '' === $_custom_kopa_qr_position ) {
			 $_custom_kopa_qr_position = $this->position;
		}
		
		if( !$display_kopa_qr_code = get_post_meta($post->ID, '_display_kopa_qr_code', true) ) {
			 $display_kopa_qr_code = 'yes';
		}

		if( $template = $this->wp_admin_qr_code_template($post->ID, false) ) {
			echo wp_kses( $template . '<hr>', array_merge(wp_kses_allowed_html('post'), [
				'input' => [
					'id' => true,
					'class' => true,
					'type' => true,
					'value' => true,
					'name' => true,
					'data-wp-taxonomy' => true,
					'autocomplete' => true,
					'aria-expanded' => true,
					'role' => true,
					'readonly' => true,
					'disabled' => true
				],
				'label' => [
					'id' => true,
					'class' => true,
					'for' => true
				]
			]) );
		}

		woocommerce_wp_select(array(
			'id' => '_custom_kopa_qr_position',
			'label' => __('Choose where you want the KÖPA QR code to appear (optional)', 'kopa-qr').'<br><br>',
			'options' => $this->positions(),
			'desc_tip' => false,
			'value' => $_custom_kopa_qr_position
		));
		
		woocommerce_wp_radio(
            array(
                'id'            => '_display_kopa_qr_code',
                'label'         => __( 'Display the KÖPA QR code.', 'kopa-qr' ),
                'options'       => array(
                    'yes'       => __( 'Yes', 'kopa-qr' ),
                    'no'        => __( 'No', 'kopa-qr' ),
                ),
                'value'         => $display_kopa_qr_code,
            )
        );
    }

	/*
	 * Save metaboxes
	 */
    public function save_meta_box($post_id, $post, $update)
    {		
        // Check if the post type is 'product'
        if (in_array($post->post_type, array(
            'product',
			'product_variation'
        )))
        {
			if($post->post_type === 'product') {
				$_custom_kopa_qr_position = sanitize_text_field($_POST['_custom_kopa_qr_position'] ?? $this->position);
				update_post_meta($post_id, '_custom_kopa_qr_position', $_custom_kopa_qr_position);
				
				$_display_kopa_qr_code = sanitize_text_field($_POST['_display_kopa_qr_code'] ?? 'yes');
				update_post_meta($post_id, '_display_kopa_qr_code', $_display_kopa_qr_code);
				
				if( sanitize_text_field($_POST['product-type'] ?? '') === 'variable' ) {
					return;
				}
			}
			
            // Check is new post or in edit mode
            $is_new_product = true;
            if (empty(get_post_meta($post_id, 'kopa_qr_post_status', true)))
            {
                update_post_meta($post_id, 'kopa_qr_post_status', 'auto-draft');
            }
            else if (get_post_meta($post_id, 'kopa_qr_post_status', true) == 'publish')
            {
                $is_new_product = false;
            }
			
			if ( $is_new_product )
            {
                update_post_meta($post_id, 'kopa_qr_code_request', self::QR_ID);

                // Update post stytus
                update_post_meta($post_id, 'kopa_qr_post_status', 'publish');
            }
        }
    }
	
	/*
	 * Save metaboxes for variations
	 */
	public function save_meta_box_variation( $variation_id, $loop ) {
		// Check is new post or in edit mode
		$is_new_product = true;
		if (empty(get_post_meta($variation_id, 'kopa_qr_post_status', true)))
		{
			update_post_meta($variation_id, 'kopa_qr_post_status', 'auto-draft');
		}
		else if (get_post_meta($variation_id, 'kopa_qr_post_status', true) == 'publish')
		{
			$is_new_product = false;
		}

		if ($is_new_product)
		{
			update_post_meta($variation_id, 'kopa_qr_code_request', self::QR_ID);

			// Update post stytus
			update_post_meta($variation_id, 'kopa_qr_post_status', 'publish');
		}
	}
	
	/*
	 * Save metaboxes inside dokan
	 */
	public function dokan_save_meta_box ($product_id, $postdata) {
		$is_new_product = true;
		if (empty(get_post_meta($product_id, 'kopa_qr_post_status', true)))
		{
			update_post_meta($product_id, 'kopa_qr_post_status', 'auto-draft');
		}
		else if (get_post_meta($product_id, 'kopa_qr_post_status', true) == 'publish')
		{
			$is_new_product = false;
		}

		if ($is_new_product)
		{
			update_post_meta($product_id, 'kopa_qr_code_request', self::QR_ID);

			// Update post stytus
			update_post_meta($product_id, 'kopa_qr_post_status', 'publish');
		}
	}

	/*
	 * Register metaboxes
	 */
	public function add_meta_box()
    {
        add_meta_box('product_image_extension', __('KÖPA QR', 'kopa-qr') , array(
            $this,
            'render_meta_box'
        ) , 'product', 'side', 'default');
    }

    /*
	 * Function that will load translations from the language files in the /languages folder in the root folder of the plugin.
	 */
    public function load_textdomain()
    {
        load_plugin_textdomain(self::DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages/');
		
		// Find proper locale
		$locale = get_locale();
		if( is_user_logged_in() ) {
			if( $user_locale = get_user_locale( get_current_user_id() ) ) {
				$locale = $user_locale;
			}
		}
		
		// Get locale
		$locale = apply_filters( 'woo_kopa_qr_plugin_locale', $locale, self::DOMAIN);
		
		// We need standard file
		$mofile = sprintf( '%s-%s.mo', self::DOMAIN, $locale );
		
		// Check first inside `/wp-content/languages/plugins`
		$domain_path = path_join( WP_LANG_DIR, 'plugins' );
		$loaded = load_textdomain( self::DOMAIN, path_join( $domain_path, $mofile ) );
		
		// Or inside `/wp-content/languages`
		if ( ! $loaded ) {
			$loaded = load_textdomain( self::DOMAIN, path_join( WP_LANG_DIR, $mofile ) );
		}
		
		// Or inside `/wp-content/plugin/kopa-qr-code/languages`
		if ( ! $loaded ) {
			$domain_path = __DIR__ . '/languages';
			$loaded = load_textdomain( self::DOMAIN, path_join( $domain_path, $mofile ) );
			
			// Or load with only locale without prefix
			if ( ! $loaded ) {
				$loaded = load_textdomain( self::DOMAIN, path_join( $domain_path, "{$locale}.mo" ) );
			}

			// Or old fashion way
			if ( ! $loaded && function_exists('load_plugin_textdomain') ) {
				load_plugin_textdomain( self::DOMAIN, false, $domain_path );
			}
		}
    }

    /*
	 * On plugin activation
	 */
	public static function on_activation() {
		register_activation_hook(__FILE__, function() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			
			if ( is_textdomain_loaded( self::DOMAIN ) ) {
				unload_textdomain( self::DOMAIN );
			}
			
			update_option('woocommerce_kopa_custom_img_position', self::DEFAULT_POSITION);
			update_option('woocommerce_kopa_max_width', self::DEFAULT_MAX_SIZE);
		});
	}
	
	/*
	 * On plugin deactivation
	 */
	public static function on_deactivation() {
		register_deactivation_hook(__FILE__, function() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			
			if ( is_textdomain_loaded( self::DOMAIN ) ) {
				unload_textdomain( self::DOMAIN );
			}
		});
	}

    /*
	 * Initialize plugin
	 */
    protected static $instance;
    public static function instance()
    {
        if (!self::$instance)
        {
            self::$instance = new self();
        }
        return self::$instance;
    }
} endif;

// On plugin activation
Kopa_QR::on_activation();
// On plugin deactivation
Kopa_QR::on_deactivation();
// Run the plugin
Kopa_QR::instance();
// The End!