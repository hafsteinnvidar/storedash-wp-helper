<?php
/**
 * Elementor Email Signup Widget
 *
 * Net-new single opt-in email-capture form placeable anywhere on the site.
 * Submits to the `storedash_optin_submit` AJAX endpoint, which records consent
 * via the opt-in webhook (source = signup_widget). ADR-019.
 *
 * @package StoreDash\Widgets
 * @since   1.2.4
 */

namespace StoreDash\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email Signup Elementor Widget
 */
class Signup_Widget extends \Elementor\Widget_Base {

	/**
	 * Get widget name
	 */
	public function get_name(): string {
		return 'storedash_signup';
	}

	/**
	 * Get widget title
	 */
	public function get_title(): string {
		return __( 'Email Signup', 'storedash' );
	}

	/**
	 * Get widget icon
	 */
	public function get_icon(): string {
		return 'eicon-email-field';
	}

	/**
	 * Get widget categories (general — placeable anywhere)
	 */
	public function get_categories(): array {
		return array( 'general' );
	}

	/**
	 * Get widget keywords
	 */
	public function get_keywords(): array {
		return array( 'signup', 'subscribe', 'newsletter', 'email', 'marketing', 'opt-in', 'storedash' );
	}

	/**
	 * Get style dependencies (conditional loading)
	 */
	public function get_style_depends(): array {
		return array( 'storedash-signup-widget' );
	}

	/**
	 * Get script dependencies (conditional loading)
	 */
	public function get_script_depends(): array {
		return array( 'storedash-signup-widget' );
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
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Content', 'storedash' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'heading',
			array(
				'label'   => __( 'Heading', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Subscribe for news and offers', 'storedash' ),
			)
		);

		$this->add_control(
			'description',
			array(
				'label'   => __( 'Description', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Be the first to hear about new products, sales, and special offers.', 'storedash' ),
			)
		);

		$this->add_control(
			'placeholder_email',
			array(
				'label'   => __( 'Email Placeholder', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Your email address', 'storedash' ),
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'   => __( 'Button Text', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Subscribe', 'storedash' ),
			)
		);

		$this->add_control(
			'submitting_text',
			array(
				'label'   => __( 'Loading Button Text', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Subscribing...', 'storedash' ),
			)
		);

		$this->add_control(
			'success_message',
			array(
				'label'   => __( 'Success Message', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Thanks for subscribing!', 'storedash' ),
			)
		);

		$this->add_control(
			'privacy_notice',
			array(
				'label'   => __( 'Privacy Notice', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'We respect your privacy. Unsubscribe at any time.', 'storedash' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Register style tab controls
	 *
	 * Control IDs from the original single "Style" section (form_bg_color, heading_color,
	 * description_color, button_text_color, button_bg_color, button_hover_bg_color) are
	 * preserved verbatim so existing pages keep their saved values.
	 */
	protected function register_style_controls(): void {
		$this->register_layout_style();
		$this->register_form_box_style();
		$this->register_heading_style();
		$this->register_description_style();
		$this->register_input_style();
		$this->register_button_style();
		$this->register_messages_style();
	}

	/**
	 * Layout — field/button arrangement
	 */
	private function register_layout_style(): void {
		$this->start_controls_section(
			'section_layout_style',
			array(
				'label' => __( 'Layout', 'storedash' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'form_layout',
			array(
				'label'     => __( 'Button Position', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'row',
				'options'   => array(
					'row'            => __( 'Right of Field', 'storedash' ),
					'row-reverse'    => __( 'Left of Field', 'storedash' ),
					'column'         => __( 'Below Field', 'storedash' ),
					'column-reverse' => __( 'Above Field', 'storedash' ),
				),
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-row' => 'flex-direction: {{VALUE}}',
				),
			)
		);

		$this->add_responsive_control(
			'form_align_items',
			array(
				'label'     => __( 'Field Alignment', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'stretch',
				'options'   => array(
					'stretch'    => __( 'Stretch', 'storedash' ),
					'flex-start' => __( 'Start', 'storedash' ),
					'center'     => __( 'Center', 'storedash' ),
					'flex-end'   => __( 'End', 'storedash' ),
				),
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-row' => 'align-items: {{VALUE}}',
				),
			)
		);

		$this->add_responsive_control(
			'field_gap',
			array(
				'label'      => __( 'Field / Button Gap', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'default'    => array(
					'size' => 10,
					'unit' => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-signup-row' => 'gap: {{SIZE}}{{UNIT}}',
				),
			)
		);

		$this->add_responsive_control(
			'input_min_width',
			array(
				'label'       => __( 'Field Min Width', 'storedash' ),
				'description' => __( 'When the field can no longer fit this width beside the button, the button wraps onto its own line.', 'storedash' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px', '%' ),
				'range'       => array(
					'px' => array(
						'min' => 60,
						'max' => 600,
					),
				),
				'default'     => array(
					'size' => 200,
					'unit' => 'px',
				),
				'selectors'   => array(
					'{{WRAPPER}} .storedash-signup-input' => 'min-width: min({{SIZE}}{{UNIT}}, 100%)',
				),
			)
		);

		$this->add_responsive_control(
			'content_alignment',
			array(
				'label'       => __( 'Text Alignment', 'storedash' ),
				'description' => __( 'Applies to every text block. Each section below can override it individually.', 'storedash' ),
				'type'        => \Elementor\Controls_Manager::CHOOSE,
				'options'     => $this->get_text_align_options( true ),
				'selectors'   => array(
					'{{WRAPPER}} .storedash-signup-widget' => '--sd-signup-align: {{VALUE}}',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Shared option set for the text-alignment CHOOSE controls
	 *
	 * @param bool $with_justify Include the Justify option (copy blocks only).
	 */
	private function get_text_align_options( bool $with_justify = false ): array {
		$options = array(
			'left'   => array(
				'title' => __( 'Left', 'storedash' ),
				'icon'  => 'eicon-text-align-left',
			),
			'center' => array(
				'title' => __( 'Center', 'storedash' ),
				'icon'  => 'eicon-text-align-center',
			),
			'right'  => array(
				'title' => __( 'Right', 'storedash' ),
				'icon'  => 'eicon-text-align-right',
			),
		);

		if ( $with_justify ) {
			$options['justify'] = array(
				'title' => __( 'Justified', 'storedash' ),
				'icon'  => 'eicon-text-align-justify',
			);
		}

		return $options;
	}

	/**
	 * Form Box — the container around everything
	 */
	private function register_form_box_style(): void {
		$this->start_controls_section(
			'section_form_style',
			array(
				'label' => __( 'Form Box', 'storedash' ),
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
					'{{WRAPPER}} .storedash-signup-form' => 'background-color: {{VALUE}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'form_border',
				'selector' => '{{WRAPPER}} .storedash-signup-form',
			)
		);

		$this->add_responsive_control(
			'form_border_radius',
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
					'{{WRAPPER}} .storedash-signup-form' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
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
					'{{WRAPPER}} .storedash-signup-form' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'form_box_shadow',
				'selector' => '{{WRAPPER}} .storedash-signup-form',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Heading
	 */
	private function register_heading_style(): void {
		$this->start_controls_section(
			'section_heading_style',
			array(
				'label' => __( 'Heading', 'storedash' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'heading_typography',
				'selector' => '{{WRAPPER}} .storedash-signup-title',
			)
		);

		$this->add_control(
			'heading_color',
			array(
				'label'     => __( 'Heading Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-title' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Text_Shadow::get_type(),
			array(
				'name'     => 'heading_text_shadow',
				'selector' => '{{WRAPPER}} .storedash-signup-title',
			)
		);

		$this->add_responsive_control(
			'heading_alignment',
			array(
				'label'     => __( 'Alignment', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => $this->get_text_align_options( true ),
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-widget' => '--sd-signup-title-align: {{VALUE}}',
				),
			)
		);

		$this->add_responsive_control(
			'heading_spacing',
			array(
				'label'      => __( 'Spacing Below', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'default'    => array(
					'size' => 10,
					'unit' => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-signup-title' => 'margin-bottom: {{SIZE}}{{UNIT}}',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Description
	 */
	private function register_description_style(): void {
		$this->start_controls_section(
			'section_description_style',
			array(
				'label' => __( 'Description', 'storedash' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'description_typography',
				'selector' => '{{WRAPPER}} .storedash-signup-description',
			)
		);

		$this->add_control(
			'description_color',
			array(
				'label'     => __( 'Description Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#666666',
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-description' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_responsive_control(
			'description_alignment',
			array(
				'label'     => __( 'Alignment', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => $this->get_text_align_options( true ),
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-widget' => '--sd-signup-description-align: {{VALUE}}',
				),
			)
		);

		$this->add_responsive_control(
			'description_spacing',
			array(
				'label'      => __( 'Spacing Below', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'default'    => array(
					'size' => 15,
					'unit' => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-signup-description' => 'margin-bottom: {{SIZE}}{{UNIT}}',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Input Field
	 */
	private function register_input_style(): void {
		$this->start_controls_section(
			'section_input_style',
			array(
				'label' => __( 'Input Field', 'storedash' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'input_typography',
				'selector' => '{{WRAPPER}} .storedash-signup-input',
			)
		);

		$this->add_control(
			'input_text_color',
			array(
				'label'     => __( 'Text Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#333333',
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-input' => 'color: {{VALUE}}',
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
					'{{WRAPPER}} .storedash-signup-input' => 'background-color: {{VALUE}}',
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
					'{{WRAPPER}} .storedash-signup-input::placeholder' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_responsive_control(
			'input_text_align',
			array(
				'label'     => __( 'Text Alignment', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => $this->get_text_align_options(),
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-widget' => '--sd-signup-input-align: {{VALUE}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'      => 'input_border',
				'selector'  => '{{WRAPPER}} .storedash-signup-input',
				'separator' => 'before',
			)
		);

		$this->add_control(
			'input_focus_border_color',
			array(
				'label'     => __( 'Focus Border Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#0073aa',
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-input:focus' => 'border-color: {{VALUE}}',
				),
			)
		);

		$this->add_responsive_control(
			'input_border_radius',
			array(
				'label'      => __( 'Border Radius', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'default'    => array(
					'top'    => '3',
					'right'  => '3',
					'bottom' => '3',
					'left'   => '3',
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-signup-input' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
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
					'{{WRAPPER}} .storedash-signup-input' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_responsive_control(
			'input_height',
			array(
				'label'      => __( 'Height', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 30,
						'max' => 100,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-signup-input' => 'height: {{SIZE}}{{UNIT}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'input_box_shadow',
				'selector' => '{{WRAPPER}} .storedash-signup-input',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Submit Button
	 */
	private function register_button_style(): void {
		$this->start_controls_section(
			'section_button_style',
			array(
				'label' => __( 'Button', 'storedash' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .storedash-signup-submit',
			)
		);

		$this->add_responsive_control(
			'button_text_align',
			array(
				'label'     => __( 'Text Alignment', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => $this->get_text_align_options(),
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-widget' => '--sd-signup-button-align: {{VALUE}}',
				),
			)
		);

		$this->start_controls_tabs( 'button_style_tabs' );

		$this->start_controls_tab(
			'button_normal',
			array(
				'label' => __( 'Normal', 'storedash' ),
			)
		);

		$this->add_control(
			'button_text_color',
			array(
				'label'     => __( 'Button Text Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-submit' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'button_bg_color',
			array(
				'label'     => __( 'Button Background Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#0073aa',
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-submit' => 'background-color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'button_border_color',
			array(
				'label'     => __( 'Border Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-submit' => 'border-color: {{VALUE}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'button_box_shadow',
				'selector' => '{{WRAPPER}} .storedash-signup-submit',
			)
		);

		$this->end_controls_tab();

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
					'{{WRAPPER}} .storedash-signup-submit:hover' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'button_hover_bg_color',
			array(
				'label'     => __( 'Button Hover Background', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#005177',
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-submit:hover' => 'background-color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'button_hover_border_color',
			array(
				'label'     => __( 'Border Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-submit:hover' => 'border-color: {{VALUE}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'button_hover_box_shadow',
				'selector' => '{{WRAPPER}} .storedash-signup-submit:hover',
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'      => 'button_border',
				'selector'  => '{{WRAPPER}} .storedash-signup-submit',
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'button_border_radius',
			array(
				'label'      => __( 'Border Radius', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'default'    => array(
					'top'    => '3',
					'right'  => '3',
					'bottom' => '3',
					'left'   => '3',
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-signup-submit' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
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
					'top'    => '10',
					'right'  => '24',
					'bottom' => '10',
					'left'   => '24',
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-signup-submit' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_control(
			'button_width',
			array(
				'label'   => __( 'Width', 'storedash' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'auto',
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
					'{{WRAPPER}} .storedash-signup-submit' => 'flex: 0 0 auto; width: {{SIZE}}{{UNIT}}',
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
					'{{WRAPPER}} .storedash-signup-submit' => 'transition-duration: {{SIZE}}s',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Privacy notice + success/error messages
	 */
	private function register_messages_style(): void {
		$this->start_controls_section(
			'section_messages_style',
			array(
				'label' => __( 'Privacy & Messages', 'storedash' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'privacy_heading',
			array(
				'label' => __( 'Privacy Notice', 'storedash' ),
				'type'  => \Elementor\Controls_Manager::HEADING,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'privacy_typography',
				'selector' => '{{WRAPPER}} .storedash-signup-privacy',
			)
		);

		$this->add_control(
			'privacy_color',
			array(
				'label'     => __( 'Text Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#666666',
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-privacy' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_responsive_control(
			'privacy_alignment',
			array(
				'label'     => __( 'Alignment', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => $this->get_text_align_options( true ),
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-widget' => '--sd-signup-privacy-align: {{VALUE}}',
				),
			)
		);

		$this->add_responsive_control(
			'privacy_spacing',
			array(
				'label'      => __( 'Spacing Above', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'default'    => array(
					'size' => 12,
					'unit' => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-signup-privacy' => 'margin-top: {{SIZE}}{{UNIT}}',
				),
			)
		);

		$this->add_control(
			'message_heading',
			array(
				'label'     => __( 'Messages', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		/*
		 * The message only exists on the frontend after a submission, so without this
		 * the controls below style an element that is never visible in the editor.
		 * Editor-only: render() ignores it, so it can never leak to the live page.
		 */
		$this->add_control(
			'preview_message_state',
			array(
				'label'       => __( 'Preview in Editor', 'storedash' ),
				'description' => __( 'Shows the message here so you can style it. Editor only — visitors still see it only after submitting.', 'storedash' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'none',
				'options'     => array(
					'none'    => __( 'Hidden', 'storedash' ),
					'success' => __( 'Success message', 'storedash' ),
					'error'   => __( 'Error message', 'storedash' ),
				),
				'render_type' => 'template',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'message_typography',
				'selector' => '{{WRAPPER}} .storedash-signup-message',
			)
		);

		$this->add_responsive_control(
			'message_alignment',
			array(
				'label'     => __( 'Alignment', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => $this->get_text_align_options(),
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-widget' => '--sd-signup-message-align: {{VALUE}}',
				),
			)
		);

		$this->add_responsive_control(
			'message_border_radius',
			array(
				'label'      => __( 'Border Radius', 'storedash' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'default'    => array(
					'top'    => '3',
					'right'  => '3',
					'bottom' => '3',
					'left'   => '3',
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .storedash-signup-message' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
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
					'{{WRAPPER}} .storedash-signup-message' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_control(
			'success_text_color',
			array(
				'label'     => __( 'Success Text Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#155724',
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-message.success' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'success_bg_color',
			array(
				'label'     => __( 'Success Background', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#d4edda',
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-message.success' => 'background-color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'success_border_color',
			array(
				'label'     => __( 'Success Border', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#c3e6cb',
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-message.success' => 'border-color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'error_text_color',
			array(
				'label'     => __( 'Error Text Color', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#721c24',
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-message.error' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'error_bg_color',
			array(
				'label'     => __( 'Error Background', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#f8d7da',
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-message.error' => 'background-color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'error_border_color',
			array(
				'label'     => __( 'Error Border', 'storedash' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#f5c6cb',
				'selectors' => array(
					'{{WRAPPER}} .storedash-signup-message.error' => 'border-color: {{VALUE}}',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Build the submit button's class list from style settings
	 *
	 * @param array $settings Widget settings.
	 */
	private function get_submit_classes( array $settings ): string {
		$classes = 'storedash-signup-submit';

		if ( ! empty( $settings['button_hover_animation'] ) ) {
			$classes .= ' elementor-animation-' . $settings['button_hover_animation'];
		}

		$button_width = $settings['button_width'] ?? 'auto';
		if ( 'full' === $button_width ) {
			$classes .= ' storedash-signup-submit--full';
		} elseif ( 'auto' === $button_width ) {
			$classes .= ' storedash-signup-submit--auto';
		}

		return $classes;
	}

	/**
	 * Render widget output on the frontend
	 */
	protected function render(): void {
		$settings     = $this->get_settings_for_display();
		$submit_class = $this->get_submit_classes( $settings );
		?>
		<div class="storedash-signup-widget">
			<form class="storedash-signup-form"
				data-success-message="<?php echo esc_attr( $settings['success_message'] ); ?>"
				data-submitting-text="<?php echo esc_attr( $settings['submitting_text'] ); ?>">
				<?php wp_nonce_field( 'storedash_optin_submit', 'storedash_optin_nonce' ); ?>

				<?php if ( ! empty( $settings['heading'] ) ) : ?>
					<h3 class="storedash-signup-title"><?php echo esc_html( $settings['heading'] ); ?></h3>
				<?php endif; ?>

				<?php if ( ! empty( $settings['description'] ) ) : ?>
					<p class="storedash-signup-description"><?php echo esc_html( $settings['description'] ); ?></p>
				<?php endif; ?>

				<div class="storedash-signup-row">
					<input type="email" name="email" class="storedash-signup-input"
						placeholder="<?php echo esc_attr( $settings['placeholder_email'] ); ?>" required />
					<button type="submit" class="<?php echo esc_attr( $submit_class ); ?>">
						<?php echo esc_html( $settings['button_text'] ); ?>
					</button>
				</div>

				<!-- Honeypot -->
				<div style="display:none !important;">
					<input type="text" name="website" tabindex="-1" autocomplete="off" />
				</div>

				<!-- Time-based trap — JS stamps render time; server rejects instant submissions -->
				<input type="hidden" name="_ts" value="" />

				<?php if ( ! empty( $settings['privacy_notice'] ) ) : ?>
					<p class="storedash-signup-privacy"><?php echo esc_html( $settings['privacy_notice'] ); ?></p>
				<?php endif; ?>

				<div class="storedash-signup-message" style="display:none;"></div>
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
		var submitClass = 'storedash-signup-submit';
		if ( settings.button_hover_animation ) {
			submitClass += ' elementor-animation-' + settings.button_hover_animation;
		}
		if ( settings.button_width === 'full' ) {
			submitClass += ' storedash-signup-submit--full';
		} else if ( settings.button_width === 'auto' ) {
			submitClass += ' storedash-signup-submit--auto';
		}

		var previewState = settings.preview_message_state || 'none';
		var previewText = previewState === 'success'
			? ( settings.success_message || '<?php echo esc_js( __( 'Thanks for subscribing!', 'storedash' ) ); ?>' )
			: '<?php echo esc_js( __( 'Please enter a valid email address.', 'storedash' ) ); ?>';
		#>
		<div class="storedash-signup-widget">
			<div class="storedash-signup-form">
				<# if ( settings.heading ) { #>
					<h3 class="storedash-signup-title">{{{ settings.heading }}}</h3>
				<# } #>

				<# if ( settings.description ) { #>
					<p class="storedash-signup-description">{{{ settings.description }}}</p>
				<# } #>

				<div class="storedash-signup-row">
					<input type="email" class="storedash-signup-input" placeholder="{{{ settings.placeholder_email }}}" />
					<button type="button" class="{{ submitClass }}">{{{ settings.button_text }}}</button>
				</div>

				<# if ( settings.privacy_notice ) { #>
					<p class="storedash-signup-privacy">{{{ settings.privacy_notice }}}</p>
				<# } #>

				<# if ( previewState !== 'none' ) { #>
					<div class="storedash-signup-message {{ previewState }}">{{{ previewText }}}</div>
				<# } #>
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
}
