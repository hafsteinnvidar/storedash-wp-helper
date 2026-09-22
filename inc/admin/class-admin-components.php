<?php
/**
 * Admin Components Helper
 *
 * Static helper class for rendering admin UI components
 *
 * @package StoreDash
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Admin_Components {

	/**
	 * Render a button
	 *
	 * @param array $args Button arguments
	 * @param bool  $echo Whether to echo or return
	 * @return string|void
	 */
	public static function button( $args = array(), $echo = true ) {
		$defaults = array(
			'text'     => '',
			'variant'  => 'secondary', // primary, secondary, tertiary, danger
			'icon'     => '',
			'id'       => '',
			'class'    => '',
			'disabled' => false,
			'href'     => '',
			'onclick'  => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$classes = array( 'storedash-wp-btn' );
		if ( $args['variant'] !== 'secondary' ) {
			$classes[] = 'storedash-wp-btn-' . $args['variant'];
		}
		if ( $args['class'] ) {
			$classes[] = $args['class'];
		}

		$tag     = $args['href'] ? 'a' : 'button';
		$attrs   = array();
		$attrs[] = 'class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		if ( $args['id'] ) {
			$attrs[] = 'id="' . esc_attr( $args['id'] ) . '"';
		}
		if ( $args['disabled'] ) {
			$attrs[] = 'disabled';
		}
		if ( $args['href'] ) {
			$attrs[] = 'href="' . esc_url( $args['href'] ) . '"';
		}
		if ( $args['onclick'] ) {
			$attrs[] = 'onclick="' . esc_js( $args['onclick'] ) . '"';
		}

		ob_start();
		?>
		<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tag is a safe internal value ('a' or 'button'). ?>
		<<?php echo $tag; ?> <?php echo implode( ' ', $attrs ); ?>>
			<?php if ( $args['icon'] ) : ?>
				<?php echo wp_kses_post( $args['icon'] ); ?>
			<?php endif; ?>
			<?php echo esc_html( $args['text'] ); ?>
		<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tag is a safe internal value ('a' or 'button'). ?>
		</<?php echo $tag; ?>>
		<?php
		$output = ob_get_clean();

		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is built from escaped components above.
			echo $output;
		} else {
			return $output;
		}
	}

	/**
	 * Render a badge
	 *
	 * @param array $args Badge arguments
	 * @param bool  $echo Whether to echo or return
	 * @return string|void
	 */
	public static function badge( $args = array(), $echo = true ) {
		$defaults = array(
			'text'    => '',
			'variant' => 'primary', // primary, attribute, taxonomy, custom
		);

		$args = wp_parse_args( $args, $defaults );

		$classes = array( 'storedash-wp-badge' );
		if ( $args['variant'] !== 'primary' ) {
			$classes[] = 'storedash-wp-badge-' . $args['variant'];
		}

		ob_start();
		?>
		<span class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
			<?php echo esc_html( $args['text'] ); ?>
		</span>
		<?php
		$output = ob_get_clean();

		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $output is an ob_get_clean() HTML string whose dynamic parts are each esc_*()-escaped as they are built above.
			echo $output;
		} else {
			return $output;
		}
	}

	/**
	 * Render a toggle switch
	 *
	 * @param array $args Toggle arguments
	 * @param bool  $echo Whether to echo or return
	 * @return string|void
	 */
	public static function toggle( $args = array(), $echo = true ) {
		$defaults = array(
			'name'           => '',
			'id'             => '',
			'checked'        => false,
			'input-class'    => '',
			'data-source-id' => '',
			'data-widget'    => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$input_attrs = array();
		if ( $args['id'] ) {
			$input_attrs[] = 'id="' . esc_attr( $args['id'] ) . '"';
		}
		if ( $args['name'] ) {
			$input_attrs[] = 'name="' . esc_attr( $args['name'] ) . '"';
		}
		if ( $args['checked'] ) {
			$input_attrs[] = 'checked';
		}
		if ( $args['input-class'] ) {
			$input_attrs[] = 'class="' . esc_attr( $args['input-class'] ) . '"';
		}
		if ( $args['data-source-id'] ) {
			$input_attrs[] = 'data-source-id="' . esc_attr( $args['data-source-id'] ) . '"';
		}
		if ( $args['data-widget'] ) {
			$input_attrs[] = 'data-widget="' . esc_attr( $args['data-widget'] ) . '"';
		}

		ob_start();
		?>
		<label class="storedash-wp-toggle">
			<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes are individually escaped above. ?>
			<input type="checkbox" <?php echo implode( ' ', $input_attrs ); ?>>
			<span class="storedash-wp-toggle-slider"></span>
		</label>
		<?php
		$output = ob_get_clean();

		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $output is an ob_get_clean() HTML string whose dynamic parts are each esc_*()-escaped as they are built above.
			echo $output;
		} else {
			return $output;
		}
	}

	/**
	 * Render a stat item
	 *
	 * @param string|int $value Stat value
	 * @param string     $label Stat label
	 * @param bool       $echo Whether to echo or return
	 * @return string|void
	 */
	public static function stat( $value, $label, $echo = true ) {
		ob_start();
		?>
		<div class="storedash-wp-stat">
			<div class="storedash-wp-stat__value"><?php echo esc_html( $value ); ?></div>
			<div class="storedash-wp-stat__label"><?php echo esc_html( $label ); ?></div>
		</div>
		<?php
		$output = ob_get_clean();

		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $output is an ob_get_clean() HTML string whose dynamic parts are each esc_*()-escaped as they are built above.
			echo $output;
		} else {
			return $output;
		}
	}

	/**
	 * Render an empty state
	 *
	 * @param array $args Empty state arguments
	 * @param bool  $echo Whether to echo or return
	 * @return string|void
	 */
	public static function empty_state( $args = array(), $echo = true ) {
		$defaults = array(
			'icon'        => '',
			'title'       => __( 'No items found', 'storedash' ),
			'description' => '',
			'action'      => '',
		);

		$args = wp_parse_args( $args, $defaults );

		ob_start();
		?>
		<div class="storedash-wp-empty-state">
			<?php if ( $args['icon'] ) : ?>
				<div class="storedash-wp-empty-state__icon">
					<?php echo wp_kses_post( $args['icon'] ); ?>
				</div>
			<?php endif; ?>

			<h3 class="storedash-wp-empty-state__title"><?php echo esc_html( $args['title'] ); ?></h3>

			<?php if ( $args['description'] ) : ?>
				<p class="storedash-wp-empty-state__description"><?php echo esc_html( $args['description'] ); ?></p>
			<?php endif; ?>

			<?php if ( $args['action'] ) : ?>
				<div class="storedash-wp-empty-state__action">
					<?php echo wp_kses_post( $args['action'] ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		$output = ob_get_clean();

		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $output is an ob_get_clean() HTML string whose dynamic parts are each esc_*()-escaped as they are built above.
			echo $output;
		} else {
			return $output;
		}
	}

	/**
	 * Render a spinner
	 *
	 * @param string $size Size: small, medium, large
	 * @param bool   $echo Whether to echo or return
	 * @return string|void
	 */
	public static function spinner( $size = 'medium', $echo = true ) {
		$class = 'storedash-wp-spinner';

		if ( $size === 'small' ) {
			$class .= ' storedash-wp-spinner--small';
		} elseif ( $size === 'large' ) {
			$class .= ' storedash-wp-spinner--large';
		}

		$output = sprintf( '<div class="%s"></div>', esc_attr( $class ) );

		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $output is an ob_get_clean() HTML string whose dynamic parts are each esc_*()-escaped as they are built above.
			echo $output;
		} else {
			return $output;
		}
	}
}

