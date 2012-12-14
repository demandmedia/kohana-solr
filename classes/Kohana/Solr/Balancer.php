<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Some comment about this class/file that is meaningful
 *
 * @package      Solr
 * @category     Search
 * @author       eHow Team
 * @author       Scott.Jungwirth
 * @copyright    (c) 2011 Demand Media, Inc.
 * @license      MIT
 */
class Kohana_Solr_Balancer {

	const ERROR_SLAVE_CONNECTION = 'Search Service Slave Connection Error';
	const ERROR_INVALID_SLAVE = 'read_services must be array of Solr instances';

	/**
	 * return a Solr_Balancer using $write_service and $read_services Solr instances
	 *
	 * @static
	 * @param Solr $write_service
	 * @param array $read_services
	 * @return Solr_Balancer
	 */
	public static function factory(Solr $write_service, array $read_services)
	{
		$class = get_called_class();
		return new $class($write_service, $read_services);
	}

	/**
	 * @var array methods which can be sent to a read service
	 */
	public $slave_methods = array(
		'search' => true,
	);

	/**
	 * @var Solr
	 */
	protected $master;

	/**
	 * @var array|Solr
	 */
	protected $slaves = array();

	/**
	 * @var Solr
	 */
	protected $active_slave;

	/**
	 * @param Solr $write_service
	 * @param array $read_services
	 */
	protected function __construct(Solr $write_service, array $read_services)
	{
		$this->master = $write_service;

		// validate slaves
		foreach ($read_services as $slave)
		{
			if ( ! ($slave instanceof Solr))
			{
				throw new Kohana_Exception(Solr_Balancer::ERROR_INVALID_SLAVE);
			}
		}

		$this->slaves = $read_services;
	}


	/**
	 * Returns a slave service if available
	 *
	 * @return Solr|bool
	 */
	protected function get_slave_service()
	{
		// check if we already have a slave
		if ($this->active_slave)
		{
			return $this->active_slave;
		}

		// if we can't use a slave
		if ( ! $this->slaves)
		{
			// return the main service
			return false;
		}

		$slaves = $this->slaves;

		// while we have more slave servers
		/** @var $slave Solr */
		while ($slave = array_pop($slaves))
		{
			// save as active slave
			$this->active_slave = $slave;

			return $slave;
		}

		// all slaves are down
		return false;
	}

	/**
	 * remove a slave from the slave list
	 *
	 * @param Solr $offline_slave
	 * @return void
	 */
	protected function remove_slave(Solr $offline_slave)
	{
		foreach ($this->slaves as $k => $slave)
		{
			if ($offline_slave === $slave)
			{
				unset($this->slaves[$k]);
				break;
			}
		}
	}

	/**
	 * @param string $func
	 * @param array  $args
	 * @return mixed
	 */
	public function __call($func, array $args)
	{
		$use_slave = Arr::get($this->slave_methods, $func);

		if ($use_slave)
		{
			while ($slave = $this->get_slave_service())
			{
				try
				{
					if ($r = $this->exec_hook($slave, $func, $args))
					{
						return $r;
					}
				}
				catch (Exception $e)
				{
					// slave is down
				}

				// no result or an error
				if ($this->active_slave)
				{
					// remove the active slave
					$this->remove_slave($this->active_slave);
					$this->active_slave = null;
				}
			}
		}

		return $this->exec_hook($this->master, $func, $args);
	}

	/**
	 * @param Solr   $solr
	 * @param string $func
	 * @param array  $args
	 * @return mixed
	 */
	protected function exec_hook(Solr $solr, $func, array $args)
	{
		return call_user_func_array(array($solr, $func), $args);
	}

	/**
	 * @param $var
	 * @return mixed
	 */
	public function __get($var)
	{
		return $this->master->$var;
	}
}
