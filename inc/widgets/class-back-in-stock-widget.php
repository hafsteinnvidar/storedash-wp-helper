<?php
/**
 * Elementor Back In Stock Widget
 *
 * @package StoreDash\Widgets
 * @since   1.0.0
 */

namespace StoreDash\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Back In Stock Elementor Widget
 */
class Back_In_Stock_Widget extends \Elementor\Widget_Base {

	/**
	 * Get widget name
	 */
	public function get_name(): string {
		return 'storedash_back_in_stock';
	}

	/**
	 * Get widget title
	 */
	public function get_title(): string {
		return __( 'Back In Stock Notification', 'storedash' );
	}

	/**
	 * Get widget icon
	 */
	public function get_icon(): string {
		return 'eicon-email-field';
	}

	/**
	 * Get widget categories
	 */
	public function get_categories(): array {
		return array( 'woocommerce-elements' );
	}

	/**
	 * Get widget keywords
	 */
	public function get_keywords(): array {
		return array( 'back in stock', 'waitlist', 'notification', 'restock', 'storedash' );
	}

	/**
	 * Get style dependencies (conditional loading)
	 */
	public function get_style_depends(): array {
		return array( 'storedash-waitlist-widget' );
	}

	/**
	 * Get script dependencies (conditional loading)
	 */
	public function get_script_depends(): array {
		return array( 'storedash-waitlist-widget' );
	}

	/**
	 * Register widget controls
	 */
	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/**
	 * Register content tab controls
	 */
	protected function register_content_controls(): void {
		// General Settings Section
		$this->start_controls_section(
			'section_general',
			array(
				'label' => __( 'General Settings', 'storedash' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'display_mode',
			array(
				'label'   => __( 'Display Mode', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'inline',
				'options' => array(
					'inline' => __( 'Inline Form', 'storedash' ),
					'modal'  => __( 'Modal Popup', 'storedash' ),
				),
			)
		);

		$this->add_control(
			'full_width',
			array(
				'label'        => __( 'Full Width', 'storedash' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'description'  => __( 'Make the widget fill the entire width of its container.', 'storedash' ),
				'label_on'     => __( 'Yes', 'storedash' ),
				'label_off'    => __( 'No', 'storedash' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'     => __( 'Button Text', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Notify Me When Available', 'storedash' ),
				'condition' => array(
					'display_mode' => 'modal',
				),
			)
		);

		$this->add_control(
			'button_icon',
			array(
				'label'       => __( 'Button Icon', 'storedash' ),
				'type'        => \Elementor\Controls_Manager::ICONS,
				'default'     => array(
					'value'   => 'fas fa-bell',
					'library' => 'fa-solid',
				),
				'recommended' => array(
					'fa-solid' => array(
						'bell',
						'envelope',
						'clock',
						'exclamation-circle',
					),
				),
				'condition'   => array(
					'display_mode' => 'modal',
				),
			)
		);

		$this->add_control(
			'button_icon_position',
			array(
				'label'     => __( 'Icon Position', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'before',
				'options'   => array(
					'before' => __( 'Before Text', 'storedash' ),
					'after'  => __( 'After Text', 'storedash' ),
				),
				'condition' => array(
					'display_mode' => 'modal',
				),
			)
		);

		$this->end_controls_section();

		// Form Content Section
		$this->start_controls_section(
			'section_form_content',
			array(
				'label' => __( 'Form Content', 'storedash' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'form_title',
			array(
				'label'   => __( 'Form Title', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Get Notified When Back In Stock', 'storedash' ),
			)
		);

		$this->add_control(
			'form_description',
			array(
				'label'   => __( 'Form Description', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Enter your email and we\'ll notify you when this product is available again.', 'storedash' ),
			)
		);

		$this->add_control(
			'show_name_field',
			array(
				'label'        => __( 'Show Name Field', 'storedash' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'storedash' ),
				'label_off'    => __( 'No', 'storedash' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_phone_field',
			array(
				'label'        => __( 'Show Phone Field', 'storedash' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'storedash' ),
				'label_off'    => __( 'No', 'storedash' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		$this->add_control(
			'privacy_notice',
			array(
				'label'   => __( 'Privacy Notice', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'We respect your privacy and will only use your email to notify you about this product.', 'storedash' ),
			)
		);

		$this->add_control(
			'submit_button_text',
			array(
				'label'   => __( 'Submit Button Text', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Notify Me', 'storedash' ),
			)
		);

		$this->add_control(
			'placeholder_name',
			array(
				'label'     => __( 'Name Placeholder', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Your Name', 'storedash' ),
				'separator' => 'before',
			)
		);

		$this->add_control(
			'placeholder_email',
			array(
				'label'   => __( 'Email Placeholder', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Your Email', 'storedash' ),
			)
		);

		$this->add_control(
			'placeholder_phone',
			array(
				'label'   => __( 'Phone Placeholder', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Your Phone (optional)', 'storedash' ),
			)
		);

		$this->add_control(
			'placeholder_variation',
			array(
				'label'     => __( 'Variation Dropdown Placeholder', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Select a variation...', 'storedash' ),
				'separator' => 'before',
			)
		);

		$this->add_control(
			'success_message',
			array(
				'label'     => __( 'Success Message', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Thank you! We\'ll notify you when this product is back in stock.', 'storedash' ),
				'separator' => 'before',
			)
		);

		$this->add_control(
			'submitting_text',
			array(
				'label'   => __( 'Loading Button Text', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Submitting...', 'storedash' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Register style tab controls
	 */
	protected function register_style_controls(): void {
		$this->register_trigger_button_style();
		$this->register_modal_style();
		$this->register_form_style();
		$this->register_title_description_style();
		$this->register_input_style();
		$this->register_submit_button_style();
		$this->register_messages_style();
	}

	/**
	 * 4a. Trigger Button Style (modal mode only)
	 */
	private function register_trigger_button_style(): void {
		$this->start_controls_section(
			'section_trigger_style',
			array(
				'label'     => __( 'Trigger Button', 'storedash' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array(
					'display_mode' => 'modal',
				),
			)
		);

		$this->add_control(
			'trigger_icon_size',
			array(
				'label'      => __( 'Icon Size', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 8,
						'max' => 60,
					),
					'em' => array(
						'min' => 0.5,
						'max' => 4,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-trigger .storedash-trigger-icon' => 'font-size: {{SIZE}}{{UNIT}}',
					'{{WRAPPER}} .storedash-waitlist-trigger .storedash-trigger-icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}}',
				),
			)
		);

		$this->add_control(
			'trigger_icon_spacing',
			array(
				'label'     => __( 'Icon Spacing', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min' => 0,
						'max' => 30,
					),
				),
				'default'   => array(
					'size' => 8,
					'unit' => 'px',
				),
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-trigger' => 'gap: {{SIZE}}{{UNIT}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'trigger_typography',
				'selector' => '{{WRAPPER}} .storedash-waitlist-trigger',
			)
		);

		$this->start_controls_tabs( 'trigger_style_tabs' );

		// Normal
		$this->start_controls_tab(
			'trigger_normal',
			array(
				'label' => __( 'Normal', 'storedash' ),
			)
		);

		$this->add_control(
			'trigger_text_color',
			array(
				'label'     => __( 'Text Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-trigger' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'trigger_bg_color',
			array(
				'label'     => __( 'Background Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#0073aa',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-trigger' => 'background-color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'trigger_border_color',
			array(
				'label'     => __( 'Border Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-trigger' => 'border-color: {{VALUE}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'trigger_box_shadow',
				'selector' => '{{WRAPPER}} .storedash-waitlist-trigger',
			)
		);

		$this->end_controls_tab();

		// Hover
		$this->start_controls_tab(
			'trigger_hover',
			array(
				'label' => __( 'Hover', 'storedash' ),
			)
		);

		$this->add_control(
			'trigger_hover_text_color',
			array(
				'label'     => __( 'Text Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-trigger:hover' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'trigger_hover_bg_color',
			array(
				'label'     => __( 'Background Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#005177',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-trigger:hover' => 'background-color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'trigger_hover_border_color',
			array(
				'label'     => __( 'Border Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-trigger:hover' => 'border-color: {{VALUE}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'trigger_hover_box_shadow',
				'selector' => '{{WRAPPER}} .storedash-waitlist-trigger:hover',
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'      => 'trigger_border',
				'selector'  => '{{WRAPPER}} .storedash-waitlist-trigger',
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'trigger_border_radius',
			array(
				'label'      => __( 'Border Radius', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-trigger' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_responsive_control(
			'trigger_padding',
			array(
				'label'      => __( 'Padding', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'default'    => array(
					'top'    => '12',
					'right'  => '24',
					'bottom' => '12',
					'left'   => '24',
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-trigger' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_control(
			'trigger_hover_animation',
			array(
				'label' => __( 'Hover Animation', 'storedash' ),
				'type'  => \Elementor\Controls_Manager::HOVER_ANIMATION,
			)
		);

		$this->add_control(
			'trigger_transition_duration',
			array(
				'label'     => __( 'Transition Duration (s)', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1,
						'step' => 0.05,
					),
				),
				'default'   => array(
					'size' => 0.3,
				),
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-trigger' => 'transition-duration: {{SIZE}}s',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * 4b. Modal Style (modal mode only)
	 */
	private function register_modal_style(): void {
		$this->start_controls_section(
			'section_modal_style',
			array(
				'label'     => __( 'Modal', 'storedash' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array(
					'display_mode' => 'modal',
				),
			)
		);

		// -- Overlay --
		$this->add_control(
			'modal_overlay_heading',
			array(
				'label' => __( 'Overlay', 'storedash' ),
				'type'  => \Elementor\Controls_Manager::HEADING,
			)
		);

		$this->add_control(
			'modal_overlay_color',
			array(
				'label'     => __( 'Overlay Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'rgba(0,0,0,0.7)',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-modal' => 'background-color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'modal_backdrop_blur',
			array(
				'label'     => __( 'Backdrop Blur', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min' => 0,
						'max' => 20,
					),
				),
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-modal' => 'backdrop-filter: blur({{SIZE}}px); -webkit-backdrop-filter: blur({{SIZE}}px)',
				),
			)
		);

		// -- Dialog --
		$this->add_control(
			'modal_dialog_heading',
			array(
				'label'     => __( 'Dialog', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'modal_bg_color',
			array(
				'label'     => __( 'Background Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-modal-content' => 'background-color: {{VALUE}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'modal_border',
				'selector' => '{{WRAPPER}} .storedash-waitlist-modal-content',
			)
		);

		$this->add_responsive_control(
			'modal_border_radius',
			array(
				'label'      => __( 'Border Radius', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'default'    => array(
					'top'    => '5',
					'right'  => '5',
					'bottom' => '5',
					'left'   => '5',
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-modal-content' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'modal_box_shadow',
				'selector' => '{{WRAPPER}} .storedash-waitlist-modal-content',
			)
		);

		$this->add_responsive_control(
			'modal_width',
			array(
				'label'      => __( 'Width', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 200,
						'max' => 900,
					),
					'%'  => array(
						'min' => 20,
						'max' => 100,
					),
				),
				'default'    => array(
					'size' => 500,
					'unit' => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-modal-content' => 'max-width: {{SIZE}}{{UNIT}}',
				),
			)
		);

		$this->add_responsive_control(
			'modal_padding',
			array(
				'label'      => __( 'Padding', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'default'    => array(
					'top'    => '20',
					'right'  => '20',
					'bottom' => '20',
					'left'   => '20',
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-modal-content .storedash-waitlist-form' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_control(
			'modal_vertical_position',
			array(
				'label'     => __( 'Vertical Position (%)', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::SLIDER,
				'range'     => array(
					'%' => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'default'   => array(
					'size' => 0,
					'unit' => '%',
				),
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-modal' => 'padding-top: {{SIZE}}%',
				),
			)
		);

		// -- Close Button --
		$this->add_control(
			'modal_close_heading',
			array(
				'label'     => __( 'Close Button', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'modal_close_color',
			array(
				'label'     => __( 'Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#aaaaaa',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-close' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'modal_close_hover_color',
			array(
				'label'     => __( 'Hover Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-close:hover' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'modal_close_size',
			array(
				'label'     => __( 'Size', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min' => 14,
						'max' => 48,
					),
				),
				'default'   => array(
					'size' => 28,
					'unit' => 'px',
				),
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-close' => 'font-size: {{SIZE}}{{UNIT}}',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * 4c. Form Container Style
	 */
	private function register_form_style(): void {
		$this->start_controls_section(
			'section_form_style',
			array(
				'label' => __( 'Form Container', 'storedash' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'form_bg_color',
			array(
				'label'     => __( 'Background Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#f8f8f8',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-form' => 'background-color: {{VALUE}}',
				),
			)
		);

		$this->add_responsive_control(
			'form_padding',
			array(
				'label'      => __( 'Padding', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'default'    => array(
					'top'    => '20',
					'right'  => '20',
					'bottom' => '20',
					'left'   => '20',
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-form' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_control(
			'form_border_radius',
			array(
				'label'      => __( 'Border Radius', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'default'    => array(
					'size' => 5,
					'unit' => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-form' => 'border-radius: {{SIZE}}{{UNIT}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'form_border',
				'selector' => '{{WRAPPER}} .storedash-waitlist-form',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'form_box_shadow',
				'selector' => '{{WRAPPER}} .storedash-waitlist-form',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * 4d. Title & Description Style
	 */
	private function register_title_description_style(): void {
		$this->start_controls_section(
			'section_title_desc_style',
			array(
				'label' => __( 'Title & Description', 'storedash' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		// -- Title --
		$this->add_control(
			'title_heading',
			array(
				'label' => __( 'Title', 'storedash' ),
				'type'  => \Elementor\Controls_Manager::HEADING,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'selector' => '{{WRAPPER}} .storedash-waitlist-title',
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => __( 'Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-title' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_responsive_control(
			'title_margin',
			array(
				'label'      => __( 'Margin', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'default'    => array(
					'top'    => '0',
					'right'  => '0',
					'bottom' => '10',
					'left'   => '0',
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-title' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		// -- Description --
		$this->add_control(
			'description_heading',
			array(
				'label'     => __( 'Description', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'description_typography',
				'selector' => '{{WRAPPER}} .storedash-waitlist-description',
			)
		);

		$this->add_control(
			'description_color',
			array(
				'label'     => __( 'Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#666666',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-description' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_responsive_control(
			'description_margin',
			array(
				'label'      => __( 'Margin', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'default'    => array(
					'top'    => '0',
					'right'  => '0',
					'bottom' => '15',
					'left'   => '0',
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-description' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * 4e. Input Fields Style
	 */
	private function register_input_style(): void {
		$this->start_controls_section(
			'section_input_style',
			array(
				'label' => __( 'Input Fields', 'storedash' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'input_typography',
				'selector' => '{{WRAPPER}} .storedash-waitlist-input',
			)
		);

		$this->add_control(
			'input_text_color',
			array(
				'label'     => __( 'Text Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#333333',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-input' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'input_bg_color',
			array(
				'label'     => __( 'Background Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-input' => 'background-color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'input_placeholder_color',
			array(
				'label'     => __( 'Placeholder Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#999999',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-input::placeholder' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'input_border',
				'selector' => '{{WRAPPER}} .storedash-waitlist-input',
			)
		);

		$this->add_control(
			'input_focus_border_color',
			array(
				'label'     => __( 'Focus Border Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#0073aa',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-input:focus' => 'border-color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'input_border_radius',
			array(
				'label'      => __( 'Border Radius', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'default'    => array(
					'size' => 3,
					'unit' => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-input' => 'border-radius: {{SIZE}}{{UNIT}}',
				),
			)
		);

		$this->add_responsive_control(
			'input_padding',
			array(
				'label'      => __( 'Padding', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'default'    => array(
					'top'    => '10',
					'right'  => '15',
					'bottom' => '10',
					'left'   => '15',
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-input' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_control(
			'input_height',
			array(
				'label'     => __( 'Height', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min' => 30,
						'max' => 80,
					),
				),
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-input' => 'height: {{SIZE}}{{UNIT}}',
				),
			)
		);

		$this->add_control(
			'input_field_spacing',
			array(
				'label'     => __( 'Field Spacing', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'default'   => array(
					'size' => 12,
					'unit' => 'px',
				),
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-form-fields' => 'gap: {{SIZE}}{{UNIT}}',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * 4f. Submit Button Style
	 */
	private function register_submit_button_style(): void {
		$this->start_controls_section(
			'section_button_style',
			array(
				'label' => __( 'Submit Button', 'storedash' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .storedash-waitlist-submit',
			)
		);

		$this->start_controls_tabs( 'button_style_tabs' );

		// Normal
		$this->start_controls_tab(
			'button_normal',
			array(
				'label' => __( 'Normal', 'storedash' ),
			)
		);

		$this->add_control(
			'button_text_color',
			array(
				'label'     => __( 'Text Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-submit' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'button_bg_color',
			array(
				'label'     => __( 'Background Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#0073aa',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-submit' => 'background-color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'button_border_color',
			array(
				'label'     => __( 'Border Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-submit' => 'border-color: {{VALUE}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'button_box_shadow',
				'selector' => '{{WRAPPER}} .storedash-waitlist-submit',
			)
		);

		$this->end_controls_tab();

		// Hover
		$this->start_controls_tab(
			'button_hover',
			array(
				'label' => __( 'Hover', 'storedash' ),
			)
		);

		$this->add_control(
			'button_hover_text_color',
			array(
				'label'     => __( 'Text Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-submit:hover' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'button_hover_bg_color',
			array(
				'label'     => __( 'Background Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#005177',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-submit:hover' => 'background-color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'button_hover_border_color',
			array(
				'label'     => __( 'Border Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-submit:hover' => 'border-color: {{VALUE}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'button_hover_box_shadow',
				'selector' => '{{WRAPPER}} .storedash-waitlist-submit:hover',
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'      => 'button_border',
				'selector'  => '{{WRAPPER}} .storedash-waitlist-submit',
				'separator' => 'before',
			)
		);

		$this->add_control(
			'button_border_radius',
			array(
				'label'      => __( 'Border Radius', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'default'    => array(
					'size' => 3,
					'unit' => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-submit' => 'border-radius: {{SIZE}}{{UNIT}}',
				),
			)
		);

		$this->add_responsive_control(
			'button_padding',
			array(
				'label'      => __( 'Padding', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'default'    => array(
					'top'    => '12',
					'right'  => '24',
					'bottom' => '12',
					'left'   => '24',
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-submit' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_control(
			'button_width',
			array(
				'label'   => __( 'Width', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'full',
				'options' => array(
					'auto'   => __( 'Auto', 'storedash' ),
					'full'   => __( 'Full Width', 'storedash' ),
					'custom' => __( 'Custom', 'storedash' ),
				),
			)
		);

		$this->add_responsive_control(
			'button_custom_width',
			array(
				'label'      => __( 'Custom Width', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 50,
						'max' => 600,
					),
					'%'  => array(
						'min' => 10,
						'max' => 100,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-submit' => 'width: {{SIZE}}{{UNIT}}',
				),
				'condition'  => array(
					'button_width' => 'custom',
				),
			)
		);

		$this->add_control(
			'button_hover_animation',
			array(
				'label' => __( 'Hover Animation', 'storedash' ),
				'type'  => \Elementor\Controls_Manager::HOVER_ANIMATION,
			)
		);

		$this->add_control(
			'button_transition_duration',
			array(
				'label'     => __( 'Transition Duration (s)', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1,
						'step' => 0.05,
					),
				),
				'default'   => array(
					'size' => 0.2,
				),
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-submit' => 'transition-duration: {{SIZE}}s',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * 4g. Messages & Privacy Style
	 */
	private function register_messages_style(): void {
		$this->start_controls_section(
			'section_messages_style',
			array(
				'label' => __( 'Messages & Privacy', 'storedash' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'message_typography',
				'label'    => __( 'Message Typography', 'storedash' ),
				'selector' => '{{WRAPPER}} .storedash-waitlist-message',
			)
		);

		// -- Success --
		$this->add_control(
			'message_success_heading',
			array(
				'label' => __( 'Success Message', 'storedash' ),
				'type'  => \Elementor\Controls_Manager::HEADING,
			)
		);

		$this->add_control(
			'message_success_color',
			array(
				'label'     => __( 'Text Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#155724',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-message.success' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'message_success_bg',
			array(
				'label'     => __( 'Background Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#d4edda',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-message.success' => 'background-color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'message_success_border_color',
			array(
				'label'     => __( 'Border Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#c3e6cb',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-message.success' => 'border-color: {{VALUE}}',
				),
			)
		);

		// -- Error --
		$this->add_control(
			'message_error_heading',
			array(
				'label'     => __( 'Error Message', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'message_error_color',
			array(
				'label'     => __( 'Text Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#721c24',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-message.error' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'message_error_bg',
			array(
				'label'     => __( 'Background Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#f8d7da',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-message.error' => 'background-color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'message_error_border_color',
			array(
				'label'     => __( 'Border Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#f5c6cb',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-message.error' => 'border-color: {{VALUE}}',
				),
			)
		);

		// -- Shared message styling --
		$this->add_control(
			'message_border_radius',
			array(
				'label'      => __( 'Border Radius', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 30,
					),
				),
				'default'    => array(
					'size' => 3,
					'unit' => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-message' => 'border-radius: {{SIZE}}{{UNIT}}',
				),
				'separator'  => 'before',
			)
		);

		$this->add_responsive_control(
			'message_padding',
			array(
				'label'      => __( 'Padding', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'default'    => array(
					'top'    => '10',
					'right'  => '15',
					'bottom' => '10',
					'left'   => '15',
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-waitlist-message' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		// -- Privacy Notice --
		$this->add_control(
			'privacy_heading',
			array(
				'label'     => __( 'Privacy Notice', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'privacy_typography',
				'selector' => '{{WRAPPER}} .storedash-waitlist-privacy',
			)
		);

		$this->add_control(
			'privacy_color',
			array(
				'label'     => __( 'Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#666666',
				'selectors' => array(
					'{{WRAPPER}} .storedash-waitlist-privacy' => 'color: {{VALUE}}',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render widget output on the frontend
	 */
	protected function render(): void {
		if ( ! is_product() ) {
			return;
		}

		global $product;
		if ( ! $product ) {
			return;
		}

		/*
		 * Stock eligibility is resolved via Waitlist_Stock, which reads
		 * `wc_product_meta_lookup` (indexed) instead of calling
		 * `$product->get_available_variations()`.
		 *
		 * The old call hydrated EVERY variation into a full
		 * WC_Product_Variation — meta, prices, images, formatted price HTML —
		 * just to read a boolean per variation. On a product with a hundred
		 * variations that is hundreds of milliseconds on every render of this
		 * widget. Attribute labels are now built from one targeted postmeta
		 * query covering only the out-of-stock variations.
		 */
		$stock = \StoreDash\Services\Waitlist\Waitlist_Stock::resolve( $product );

		if ( empty( $stock['eligible'] ) ) {
			return;
		}

		$is_variable    = (bool) $stock['is_variable'];
		$all_oos        = (bool) $stock['all_oos'];
		$oos_variations = $stock['variations'];

		$settings     = $this->get_settings_for_display();
		$product_id   = (int) $stock['product_id'];
		$variation_id = (int) $stock['variation_id'];

		$unique_id = 'storedash-waitlist-' . $this->get_id();

		$widget_class = 'storedash-waitlist-widget';
		if ( $settings['full_width'] === 'yes' ) {
			$widget_class .= ' storedash-waitlist-widget--full-width';
		}

		?>
		<div class="<?php echo esc_attr( $widget_class ); ?>" data-product-id="<?php echo esc_attr( $product_id ); ?>" data-variation-id="<?php echo esc_attr( $variation_id ); ?>" data-product-type="<?php echo esc_attr( $is_variable ? 'variable' : 'simple' ); ?>" data-all-oos="<?php echo esc_attr( $all_oos ? '1' : '0' ); ?>">
			<?php if ( $settings['display_mode'] === 'modal' ) : ?>
				<?php
				$trigger_class = 'storedash-waitlist-trigger';
				if ( ! empty( $settings['trigger_hover_animation'] ) ) {
					$trigger_class .= ' elementor-animation-' . $settings['trigger_hover_animation'];
				}
				?>
				<button type="button" class="<?php echo esc_attr( $trigger_class ); ?>" data-target="<?php echo esc_attr( $unique_id ); ?>">
					<?php if ( ! empty( $settings['button_icon']['value'] ) && $settings['button_icon_position'] === 'before' ) : ?>
						<span class="storedash-trigger-icon">
							<?php \Elementor\Icons_Manager::render_icon( $settings['button_icon'], array( 'aria-hidden' => 'true' ) ); ?>
						</span>
					<?php endif; ?>
					<span class="storedash-trigger-text"><?php echo esc_html( $settings['button_text'] ); ?></span>
					<?php if ( ! empty( $settings['button_icon']['value'] ) && $settings['button_icon_position'] === 'after' ) : ?>
						<span class="storedash-trigger-icon">
							<?php \Elementor\Icons_Manager::render_icon( $settings['button_icon'], array( 'aria-hidden' => 'true' ) ); ?>
						</span>
					<?php endif; ?>
				</button>
				<div id="<?php echo esc_attr( $unique_id ); ?>" class="storedash-waitlist-modal" style="display:none;">
					<div class="storedash-waitlist-modal-content">
						<span class="storedash-waitlist-close">&times;</span>
						<?php $this->render_form( $settings, $oos_variations ); ?>
					</div>
				</div>
			<?php else : ?>
				<?php $this->render_form( $settings, $oos_variations ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the form HTML
	 */
	protected function render_form( array $settings, array $oos_variations = array() ): void {
		$product_id   = 0;
		$variation_id = 0;

		global $product;
		if ( $product ) {
			$product_id = $product->get_id();
			if ( $product->is_type( 'variation' ) ) {
				$variation_id = $product->get_id();
				$product_id   = $product->get_parent_id();
			}
		}

		$submit_class = 'storedash-waitlist-submit';
		if ( ! empty( $settings['button_hover_animation'] ) ) {
			$submit_class .= ' elementor-animation-' . $settings['button_hover_animation'];
		}

		$button_width = $settings['button_width'] ?? 'full';
		if ( $button_width === 'full' ) {
			$submit_class .= ' storedash-waitlist-submit--full';
		} elseif ( $button_width === 'auto' ) {
			$submit_class .= ' storedash-waitlist-submit--auto';
		}

		?>
		<div class="storedash-waitlist-form">
			<?php if ( ! empty( $settings['form_title'] ) ) : ?>
				<h3 class="storedash-waitlist-title"><?php echo esc_html( $settings['form_title'] ); ?></h3>
			<?php endif; ?>

			<?php if ( ! empty( $settings['form_description'] ) ) : ?>
				<p class="storedash-waitlist-description"><?php echo esc_html( $settings['form_description'] ); ?></p>
			<?php endif; ?>

			<form class="storedash-waitlist-form-fields" data-product-id="<?php echo esc_attr( $product_id ); ?>" data-variation-id="<?php echo esc_attr( $variation_id ); ?>" data-success-message="<?php echo esc_attr( $settings['success_message'] ); ?>" data-submitting-text="<?php echo esc_attr( $settings['submitting_text'] ); ?>">
				<?php wp_nonce_field( 'storedash_waitlist_submit', 'storedash_waitlist_nonce' ); ?>

				<?php if ( ! empty( $oos_variations ) ) : ?>
					<div class="storedash-waitlist-field">
						<select class="storedash-waitlist-input storedash-waitlist-variation-select">
							<?php if ( count( $oos_variations ) > 1 ) : ?>
								<option value="0"><?php echo esc_html( $settings['placeholder_variation'] ); ?></option>
							<?php endif; ?>
							<?php foreach ( $oos_variations as $v ) : ?>
								<option value="<?php echo esc_attr( $v['variation_id'] ); ?>"><?php echo esc_html( $v['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>

				<?php if ( $settings['show_name_field'] === 'yes' ) : ?>
					<div class="storedash-waitlist-field">
						<input type="text" name="customer_name" class="storedash-waitlist-input" placeholder="<?php echo esc_attr( $settings['placeholder_name'] ); ?>" required />
					</div>
				<?php endif; ?>

				<div class="storedash-waitlist-field">
					<input type="email" name="customer_email" class="storedash-waitlist-input" placeholder="<?php echo esc_attr( $settings['placeholder_email'] ); ?>" required />
				</div>

				<?php if ( $settings['show_phone_field'] === 'yes' ) : ?>
					<div class="storedash-waitlist-field">
						<input type="tel" name="customer_phone" class="storedash-waitlist-input" placeholder="<?php echo esc_attr( $settings['placeholder_phone'] ); ?>" />
					</div>
				<?php endif; ?>

				<!-- Honeypot -->
				<div style="display:none !important;"><input type="text" name="website" tabindex="-1" autocomplete="off" /></div>

				<?php
				// Anti-bot stamp — see Waitlist_Handler::form_stamp(). Must match
				// the shared renderer's fields exactly; both post to the same
				// AJAX handler.
				$stamp = \StoreDash\Services\Waitlist\Waitlist_Handler::form_stamp();
				?>
				<input type="hidden" name="_ts" value="<?php echo esc_attr( (string) $stamp['ts'] ); ?>" />
				<input type="hidden" name="_tsh" value="<?php echo esc_attr( $stamp['hash'] ); ?>" />

				<?php if ( ! empty( $settings['privacy_notice'] ) ) : ?>
					<p class="storedash-waitlist-privacy"><?php echo esc_html( $settings['privacy_notice'] ); ?></p>
				<?php endif; ?>

				<button type="submit" class="<?php echo esc_attr( $submit_class ); ?>">
					<?php echo esc_html( $settings['submit_button_text'] ); ?>
				</button>

				<div class="storedash-waitlist-message" style="display:none;"></div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render widget output in the editor (Elementor preview)
	 */
	protected function content_template(): void {
		?>
		<#
		var uniqueId = 'storedash-waitlist-preview-' + Date.now();

		var triggerClass = 'storedash-waitlist-trigger';
		if ( settings.trigger_hover_animation ) {
			triggerClass += ' elementor-animation-' + settings.trigger_hover_animation;
		}

		var submitClass = 'storedash-waitlist-submit';
		if ( settings.button_hover_animation ) {
			submitClass += ' elementor-animation-' + settings.button_hover_animation;
		}
		if ( settings.button_width === 'full' ) {
			submitClass += ' storedash-waitlist-submit--full';
		} else if ( settings.button_width === 'auto' ) {
			submitClass += ' storedash-waitlist-submit--auto';
		}

		var iconHtml = '';
		if ( settings.button_icon && settings.button_icon.value ) {
			var iconTag = elementor.helpers.renderIcon( view, settings.button_icon, { 'aria-hidden': 'true' }, 'i', 'object' );
			if ( iconTag && iconTag.value ) {
				iconHtml = '<span class="storedash-trigger-icon">' + iconTag.value + '</span>';
			}
		}

		var widgetClass = 'storedash-waitlist-widget';
		if ( settings.full_width === 'yes' ) {
			widgetClass += ' storedash-waitlist-widget--full-width';
		}
		#>
		<div class="{{ widgetClass }}">
			<# if ( settings.display_mode === 'modal' ) { #>
				<button type="button" class="{{ triggerClass }}">
					<# if ( iconHtml && settings.button_icon_position === 'before' ) { #>
						{{{ iconHtml }}}
					<# } #>
					<span class="storedash-trigger-text">{{{ settings.button_text }}}</span>
					<# if ( iconHtml && settings.button_icon_position === 'after' ) { #>
						{{{ iconHtml }}}
					<# } #>
				</button>
			<# } #>

			<div class="storedash-waitlist-form">
				<# if ( settings.form_title ) { #>
					<h3 class="storedash-waitlist-title">{{{ settings.form_title }}}</h3>
				<# } #>

				<# if ( settings.form_description ) { #>
					<p class="storedash-waitlist-description">{{{ settings.form_description }}}</p>
				<# } #>

				<form class="storedash-waitlist-form-fields">
					<# if ( settings.show_name_field === 'yes' ) { #>
						<div class="storedash-waitlist-field">
							<input type="text" class="storedash-waitlist-input" placeholder="{{{ settings.placeholder_name }}}" />
						</div>
					<# } #>

					<div class="storedash-waitlist-field">
						<input type="email" class="storedash-waitlist-input" placeholder="{{{ settings.placeholder_email }}}" />
					</div>

					<# if ( settings.show_phone_field === 'yes' ) { #>
						<div class="storedash-waitlist-field">
							<input type="tel" class="storedash-waitlist-input" placeholder="{{{ settings.placeholder_phone }}}" />
						</div>
					<# } #>

					<# if ( settings.privacy_notice ) { #>
						<p class="storedash-waitlist-privacy">{{{ settings.privacy_notice }}}</p>
					<# } #>

					<button type="button" class="{{ submitClass }}">
						{{{ settings.submit_button_text }}}
					</button>

					<div class="storedash-waitlist-message" style="display:none;">
						<span class="success" style="display:none;"></span>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Widget performance optimization
	 */
	public function has_widget_inner_wrapper(): bool {
		return false;
	}

	/**
	 * This widget has dynamic content (product-specific)
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}
}
