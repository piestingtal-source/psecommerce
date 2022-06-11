<?php
if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
	require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
}

if ( is_multisite() ) {

//Product tags cloud
	class PSeCommerce_Global_Tag_Cloud_Widget extends WP_Widget {

		function __construct() {
			$widget_ops = array( 'classname'   => 'mp_widget mp_global_tag_cloud_widget',
			                     'description' => __( "Zeigt die weltweit am häufigsten verwendeten Produkt-Tags im Cloud-Format aus Netzwerk-PSeCommerce-Shops an.", 'mp' )
			);
			parent::__construct( 'mp_global_tag_cloud_widget', __( 'PSEC Netzwerk-Tag-Cloud', 'mp' ), $widget_ops );
		}

		function widget( $args, $instance ) {
			extract( $args );

			if ( ! empty( $instance['title'] ) ) {
				$title = $instance['title'];
			} else {
				$title = __( 'Globale Produkt-Tags', 'mp' );
			}
			$title = apply_filters( 'widget_title', $title, $instance, $this->id_base );

			echo $before_widget;
			if ( $title ) {
				echo $before_title . $title . $after_title;
			}

			mp_global_taxonomy_list( 'product_tag', $instance, true );

			echo $after_widget;
		}

		function update( $new_instance, $old_instance ) {
			$instance['title'] = strip_tags( stripslashes( $new_instance['title'] ) );

			return $instance;
		}

		function form( $instance ) {
			$instance = wp_parse_args( (array) $instance, array( 'title'    => __( 'Globale Produkt-Tags', 'mp' ),
			                                                     'taxonomy' => 'product_tag'
			) );
			?>
			<p><label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Titel:', 'mp' ) ?></label>
				<input type="text" class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>"
				       name="<?php echo $this->get_field_name( 'title' ); ?>" value="<?php
				if ( isset( $instance['title'] ) ) {
					echo esc_attr( $instance['title'] );
				}
				?>"/></p>
			<?php
		}

	}

	//add_action( 'widgets_init', create_function( '', 'return register_widget("PSeCommerce_Global_Tag_Cloud_Widget");' ) );
	add_action( 'widgets_init', 'PSeCommerce_Global_Tag_Cloud_Widget' ); function PSeCommerce_Global_Tag_Cloud_Widget() {return register_widget('PSeCommerce_Global_Tag_Cloud_Widget');}
}
?>