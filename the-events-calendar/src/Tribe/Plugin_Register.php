<?php


class Tribe__Events__Plugin_Register extends Tribe__Abstract_Plugin_Register {

	protected $main_class   = 'Tribe__Events__Main';
	/**
	 * `addon-dependencies` AKA Min plugin versions.
	 *
	 * @var string[][]
	 */
	protected $dependencies = [
		'addon-dependencies' => [
			'Tribe__Events__Pro__Main'                 => '7.6.0-dev',
			'Tribe__Events__Filterbar__View'           => '5.5.7-dev',
			'Tribe__Events__Community__Main'           => '5.0.7-dev',
			'Tribe__Tickets__Main'                     => '5.24.0-dev',
			'Tribe__Tickets_Plus__Main'                => '6.5.0-dev',
			'Tribe__Events__Tickets__Eventbrite__Main' => '4.6.14-dev',
			'Tribe__Events__Community__Tickets__Main'  => '4.9.3-dev',
			'Tribe\Events\Virtual'                     => '1.15.5-dev',
			'TEC\Event_Automator'                      => '1.3.1-dev',
		],
	];

	public function __construct() {
		$this->base_dir = TRIBE_EVENTS_FILE;
		$this->version  = Tribe__Events__Main::VERSION;

		$this->register_plugin();
	}
}
