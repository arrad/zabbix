<?php
/*
** Zabbix
** Copyright (C) 2001-2014 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


/**
 * Class containing methods for operations with actions.
 *
 * @package API
 */
class CAction extends CApiService {

	protected $tableName = 'actions';
	protected $tableAlias = 'a';
	protected $sortColumns = array('actionid', 'name', 'status');

	/**
	 * Get actions data
	 *
	 * @param array $options
	 * @param array $options['itemids']
	 * @param array $options['hostids']
	 * @param array $options['groupids']
	 * @param array $options['actionids']
	 * @param array $options['applicationids']
	 * @param array $options['status']
	 * @param array $options['editable']
	 * @param array $options['extendoutput']
	 * @param array $options['count']
	 * @param array $options['pattern']
	 * @param array $options['limit']
	 * @param array $options['order']
	 *
	 * @return array|int item data as array or false if error
	 */
	public function get($options = array()) {
		$result = array();
		$userType = self::$userData['type'];
		$userId = self::$userData['userid'];

		$sqlParts = array(
			'select'	=> array('actions' => 'a.actionid'),
			'from'		=> array('actions' => 'actions a'),
			'where'		=> array(),
			'order'		=> array(),
			'limit'		=> null
		);

		$defOptions = array(
			'groupids'					=> null,
			'hostids'					=> null,
			'actionids'					=> null,
			'triggerids'				=> null,
			'mediatypeids'				=> null,
			'usrgrpids'					=> null,
			'userids'					=> null,
			'scriptids'					=> null,
			'nopermissions'				=> null,
			'editable'					=> null,
			// filter
			'filter'					=> null,
			'search'					=> null,
			'searchByAny'				=> null,
			'startSearch'				=> null,
			'excludeSearch'				=> null,
			'searchWildcardsEnabled'	=> null,
			// output
			'output'					=> API_OUTPUT_EXTEND,
			'selectFilter'				=> null,
			'selectOperations'			=> null,
			'countOutput'				=> null,
			'preservekeys'				=> null,
			'sortfield'					=> '',
			'sortorder'					=> '',
			'limit'						=> null
		);
		$options = zbx_array_merge($defOptions, $options);

		// editable + PERMISSION CHECK
		if ($userType != USER_TYPE_SUPER_ADMIN && !$options['nopermissions']) {
			// conditions are checked here by sql, operations after, by api queries
			$permission = $options['editable'] ? PERM_READ_WRITE : PERM_READ;

			$userGroups = getUserGroupsByUserId($userId);

			// condition hostgroup
			$sqlParts['where'][] = 'NOT EXISTS ('.
					'SELECT NULL'.
					' FROM conditions cc'.
						' LEFT JOIN rights r'.
							' ON r.id='.zbx_dbcast_2bigint('cc.value').
								' AND '.dbConditionInt('r.groupid', $userGroups).
					' WHERE a.actionid=cc.actionid'.
						' AND cc.conditiontype='.CONDITION_TYPE_HOST_GROUP.
					' GROUP BY cc.value'.
					' HAVING MIN(r.permission) IS NULL'.
						' OR MIN(r.permission)='.PERM_DENY.
						' OR MAX(r.permission)<'.$permission.
					')';

			// condition host or template
			$sqlParts['where'][] = 'NOT EXISTS ('.
					'SELECT NULL'.
					' FROM conditions cc,hosts_groups hgg'.
						' LEFT JOIN rights r'.
							' ON r.id=hgg.groupid'.
								' AND '.dbConditionInt('r.groupid', $userGroups).
					' WHERE a.actionid=cc.actionid'.
						' AND '.zbx_dbcast_2bigint('cc.value').'=hgg.hostid'.
						' AND cc.conditiontype IN ('.CONDITION_TYPE_HOST.','.CONDITION_TYPE_TEMPLATE.')'.
					' GROUP BY cc.value'.
					' HAVING MIN(r.permission) IS NULL'.
						' OR MIN(r.permission)='.PERM_DENY.
						' OR MAX(r.permission)<'.$permission.
					')';

			// condition trigger
			$sqlParts['where'][] = 'NOT EXISTS ('.
					'SELECT NULL'.
					' FROM conditions cc,functions f,items i,hosts_groups hgg'.
						' LEFT JOIN rights r'.
							' ON r.id=hgg.groupid'.
								' AND '.dbConditionInt('r.groupid', $userGroups).
					' WHERE a.actionid=cc.actionid'.
						' AND '.zbx_dbcast_2bigint('cc.value').'=f.triggerid'.
						' AND f.itemid=i.itemid'.
						' AND i.hostid=hgg.hostid'.
						' AND cc.conditiontype='.CONDITION_TYPE_TRIGGER.
					' GROUP BY cc.value'.
					' HAVING MIN(r.permission) IS NULL'.
						' OR MIN(r.permission)='.PERM_DENY.
						' OR MAX(r.permission)<'.$permission.
					')';
		}

		// actionids
		if (!is_null($options['actionids'])) {
			zbx_value2array($options['actionids']);

			$sqlParts['where'][] = dbConditionInt('a.actionid', $options['actionids']);
		}

		// groupids
		if (!is_null($options['groupids'])) {
			zbx_value2array($options['groupids']);

			$sqlParts['from']['conditions_groups'] = 'conditions cg';
			$sqlParts['where'][] = dbConditionString('cg.value', $options['groupids']);
			$sqlParts['where']['ctg'] = 'cg.conditiontype='.CONDITION_TYPE_HOST_GROUP;
			$sqlParts['where']['acg'] = 'a.actionid=cg.actionid';
		}

		// hostids
		if (!is_null($options['hostids'])) {
			zbx_value2array($options['hostids']);

			$sqlParts['from']['conditions_hosts'] = 'conditions ch';
			$sqlParts['where'][] = dbConditionString('ch.value', $options['hostids']);
			$sqlParts['where']['cth'] = 'ch.conditiontype='.CONDITION_TYPE_HOST;
			$sqlParts['where']['ach'] = 'a.actionid=ch.actionid';
		}

		// triggerids
		if (!is_null($options['triggerids'])) {
			zbx_value2array($options['triggerids']);

			$sqlParts['from']['conditions_triggers'] = 'conditions ct';
			$sqlParts['where'][] = dbConditionString('ct.value', $options['triggerids']);
			$sqlParts['where']['ctt'] = 'ct.conditiontype='.CONDITION_TYPE_TRIGGER;
			$sqlParts['where']['act'] = 'a.actionid=ct.actionid';
		}

		// mediatypeids
		if (!is_null($options['mediatypeids'])) {
			zbx_value2array($options['mediatypeids']);

			$sqlParts['from']['opmessage'] = 'opmessage om';
			$sqlParts['from']['operations_media'] = 'operations omed';
			$sqlParts['where'][] = dbConditionInt('om.mediatypeid', $options['mediatypeids']);
			$sqlParts['where']['aomed'] = 'a.actionid=omed.actionid';
			$sqlParts['where']['oom'] = 'omed.operationid=om.operationid';
		}

		// operation messages
		// usrgrpids
		if (!is_null($options['usrgrpids'])) {
			zbx_value2array($options['usrgrpids']);

			$sqlParts['from']['opmessage_grp'] = 'opmessage_grp omg';
			$sqlParts['from']['operations_usergroups'] = 'operations oug';
			$sqlParts['where'][] = dbConditionInt('omg.usrgrpid', $options['usrgrpids']);
			$sqlParts['where']['aoug'] = 'a.actionid=oug.actionid';
			$sqlParts['where']['oomg'] = 'oug.operationid=omg.operationid';
		}

		// userids
		if (!is_null($options['userids'])) {
			zbx_value2array($options['userids']);

			$sqlParts['from']['opmessage_usr'] = 'opmessage_usr omu';
			$sqlParts['from']['operations_users'] = 'operations ou';
			$sqlParts['where'][] = dbConditionInt('omu.userid', $options['userids']);
			$sqlParts['where']['aou'] = 'a.actionid=ou.actionid';
			$sqlParts['where']['oomu'] = 'ou.operationid=omu.operationid';
		}

		// operation commands
		// scriptids
		if (!is_null($options['scriptids'])) {
			zbx_value2array($options['scriptids']);

			$sqlParts['from']['opcommand'] = 'opcommand oc';
			$sqlParts['from']['operations_scripts'] = 'operations os';
			$sqlParts['where'][] = '('.dbConditionInt('oc.scriptid', $options['scriptids']).
				' AND oc.type='.ZBX_SCRIPT_TYPE_GLOBAL_SCRIPT.')';
			$sqlParts['where']['aos'] = 'a.actionid=os.actionid';
			$sqlParts['where']['ooc'] = 'os.operationid=oc.operationid';
		}

		// filter
		if (is_array($options['filter'])) {
			$this->dbFilter('actions a', $options, $sqlParts);
		}

		// search
		if (is_array($options['search'])) {
			zbx_db_search('actions a', $options, $sqlParts);
		}

		// limit
		if (zbx_ctype_digit($options['limit']) && $options['limit']) {
			$sqlParts['limit'] = $options['limit'];
		}

		$actionIds = array();

		$sqlParts = $this->applyQueryOutputOptions($this->tableName(), $this->tableAlias(), $options, $sqlParts);
		$sqlParts = $this->applyQuerySortOptions($this->tableName(), $this->tableAlias(), $options, $sqlParts);
		$dbRes = DBselect($this->createSelectQueryFromParts($sqlParts), $sqlParts['limit']);
		while ($action = DBfetch($dbRes)) {
			if ($options['countOutput']) {
				$result = $action['rowscount'];
			}
			else {
				$actionIds[$action['actionid']] = $action['actionid'];

				$result[$action['actionid']] = $action;
			}
		}

		if ($userType != USER_TYPE_SUPER_ADMIN && !$options['nopermissions']) {
			// check hosts, templates
			$hosts = array();
			$hostIds = array();
			$sql = 'SELECT o.actionid,och.hostid'.
					' FROM operations o,opcommand_hst och'.
					' WHERE o.operationid=och.operationid'.
						' AND och.hostid<>0'.
						' AND '.dbConditionInt('o.actionid', $actionIds);
			$dbHosts = DBselect($sql);
			while ($host = DBfetch($dbHosts)) {
				if (!isset($hosts[$host['hostid']])) {
					$hosts[$host['hostid']] = array();
				}
				$hosts[$host['hostid']][$host['actionid']] = $host['actionid'];
				$hostIds[$host['hostid']] = $host['hostid'];
			}

			$dbTemplates = DBselect(
				'SELECT o.actionid,ot.templateid'.
				' FROM operations o,optemplate ot'.
				' WHERE o.operationid=ot.operationid'.
					' AND '.dbConditionInt('o.actionid', $actionIds)
			);
			while ($template = DBfetch($dbTemplates)) {
				if (!isset($hosts[$template['templateid']])) {
					$hosts[$template['templateid']] = array();
				}
				$hosts[$template['templateid']][$template['actionid']] = $template['actionid'];
				$hostIds[$template['templateid']] = $template['templateid'];
			}

			$allowedHosts = API::Host()->get(array(
				'hostids' => $hostIds,
				'output' => array('hostid'),
				'editable' => $options['editable'],
				'templated_hosts' => true,
				'preservekeys' => true
			));
			foreach ($hostIds as $hostId) {
				if (isset($allowedHosts[$hostId])) {
					continue;
				}
				foreach ($hosts[$hostId] as $actionId) {
					unset($result[$actionId], $actionIds[$actionId]);
				}
			}
			unset($allowedHosts);

			// check hostgroups
			$groups = array();
			$groupIds = array();
			$dbGroups = DBselect(
				'SELECT o.actionid,ocg.groupid'.
				' FROM operations o,opcommand_grp ocg'.
				' WHERE o.operationid=ocg.operationid'.
					' AND '.dbConditionInt('o.actionid', $actionIds)
			);
			while ($group = DBfetch($dbGroups)) {
				if (!isset($groups[$group['groupid']])) {
					$groups[$group['groupid']] = array();
				}
				$groups[$group['groupid']][$group['actionid']] = $group['actionid'];
				$groupIds[$group['groupid']] = $group['groupid'];
			}

			$dbGroups = DBselect(
				'SELECT o.actionid,og.groupid'.
				' FROM operations o,opgroup og'.
				' WHERE o.operationid=og.operationid'.
					' AND '.dbConditionInt('o.actionid', $actionIds)
			);
			while ($group = DBfetch($dbGroups)) {
				if (!isset($groups[$group['groupid']])) {
					$groups[$group['groupid']] = array();
				}
				$groups[$group['groupid']][$group['actionid']] = $group['actionid'];
				$groupIds[$group['groupid']] = $group['groupid'];
			}

			$allowedGroups = API::HostGroup()->get(array(
				'groupids' => $groupIds,
				'output' => array('groupid'),
				'editable' => $options['editable'],
				'preservekeys' => true
			));
			foreach ($groupIds as $groupId) {
				if (isset($allowedGroups[$groupId])) {
					continue;
				}
				foreach ($groups[$groupId] as $actionId) {
					unset($result[$actionId], $actionIds[$actionId]);
				}
			}
			unset($allowedGroups);

			// check scripts
			$scripts = array();
			$scriptIds = array();
			$dbScripts = DBselect(
				'SELECT o.actionid,oc.scriptid'.
				' FROM operations o,opcommand oc'.
				' WHERE o.operationid=oc.operationid'.
					' AND '.dbConditionInt('o.actionid', $actionIds).
					' AND oc.type='.ZBX_SCRIPT_TYPE_GLOBAL_SCRIPT
			);
			while ($script = DBfetch($dbScripts)) {
				if (!isset($scripts[$script['scriptid']])) {
					$scripts[$script['scriptid']] = array();
				}
				$scripts[$script['scriptid']][$script['actionid']] = $script['actionid'];
				$scriptIds[$script['scriptid']] = $script['scriptid'];
			}

			$allowedScripts = API::Script()->get(array(
				'scriptids' => $scriptIds,
				'output' => array('scriptid'),
				'preservekeys' => true
			));
			foreach ($scriptIds as $scriptId) {
				if (isset($allowedScripts[$scriptId])) {
					continue;
				}
				foreach ($scripts[$scriptId] as $actionId) {
					unset($result[$actionId], $actionIds[$actionId]);
				}
			}
			unset($allowedScripts);

			// check users
			$users = array();
			$userIds = array();
			$dbUsers = DBselect(
				'SELECT o.actionid,omu.userid'.
				' FROM operations o,opmessage_usr omu'.
				' WHERE o.operationid=omu.operationid'.
					' AND '.dbConditionInt('o.actionid', $actionIds)
			);
			while ($user = DBfetch($dbUsers)) {
				if (!isset($users[$user['userid']])) {
					$users[$user['userid']] = array();
				}
				$users[$user['userid']][$user['actionid']] = $user['actionid'];
				$userIds[$user['userid']] = $user['userid'];
			}

			$allowedUsers = API::User()->get(array(
				'userids' => $userIds,
				'output' => array('userid'),
				'preservekeys' => true
			));
			foreach ($userIds as $userId) {
				if (isset($allowedUsers[$userId])) {
					continue;
				}
				foreach ($users[$userId] as $actionId) {
					unset($result[$actionId], $actionIds[$actionId]);
				}
			}

			// check usergroups
			$userGroups = array();
			$userGroupIds = array();
			$dbUserGroups = DBselect(
				'SELECT o.actionid,omg.usrgrpid'.
				' FROM operations o,opmessage_grp omg'.
				' WHERE o.operationid=omg.operationid'.
					' AND '.dbConditionInt('o.actionid', $actionIds)
			);
			while ($userGroup = DBfetch($dbUserGroups)) {
				if (!isset($userGroups[$userGroup['usrgrpid']])) {
					$userGroups[$userGroup['usrgrpid']] = array();
				}
				$userGroups[$userGroup['usrgrpid']][$userGroup['actionid']] = $userGroup['actionid'];
				$userGroupIds[$userGroup['usrgrpid']] = $userGroup['usrgrpid'];
			}

			$allowedUserGroups = API::UserGroup()->get(array(
				'usrgrpids' => $userGroupIds,
				'output' => array('usrgrpid'),
				'preservekeys' => true
			));

			foreach ($userGroupIds as $userGroupId) {
				if (isset($allowedUserGroups[$userGroupId])) {
					continue;
				}
				foreach ($userGroups[$userGroupId] as $actionId) {
					unset($result[$actionId], $actionIds[$actionId]);
				}
			}
		}

		if (!is_null($options['countOutput'])) {
			return $result;
		}

		if ($result) {
			$result = $this->addRelatedObjects($options, $result);
		}

		// removing keys (hash -> array)
		if (is_null($options['preservekeys'])) {
			$result = zbx_cleanHashes($result);
		}

		return $result;
	}

	/**
	 * Check if action exists.
	 *
	 * @deprecated	As of version 2.4, use get method instead.
	 *
	 * @param array	$object
	 *
	 * @return bool
	 */
	public function exists($object) {
		$this->deprecated('action.exists method is deprecated.');

		$objs = $this->get(array(
			'filter' => zbx_array_mintersect(array(array('actionid', 'name')), $object),
			'output' => array('actionid'),
			'limit' => 1
		));

		return !empty($objs);
	}

	/**
	 * Add actions
	 *
	 * @param _array $actions multidimensional array with actions data
	 * @param array $actions[0,...]['expression']
	 * @param array $actions[0,...]['description']
	 * @param array $actions[0,...]['type'] OPTIONAL
	 * @param array $actions[0,...]['priority'] OPTIONAL
	 * @param array $actions[0,...]['status'] OPTIONAL
	 * @param array $actions[0,...]['comments'] OPTIONAL
	 * @param array $actions[0,...]['url'] OPTIONAL
	 * @param array $actions[0,...]['filter'] OPTIONAL
	 * @return boolean
	 */
	public function create($actions) {
		$actions = zbx_toArray($actions);

		// check fields
		$actionDbFields = array(
			'name' => null,
			'eventsource' => null
		);
		$duplicates = array();
		foreach ($actions as $action) {
			if (!check_db_fields($actionDbFields, $action)) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Incorrect parameter for action "%1$s".', $action['name']));
			}
			if (isset($action['esc_period']) && $action['esc_period'] < SEC_PER_MIN && $action['eventsource'] == EVENT_SOURCE_TRIGGERS) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('Action "%1$s" has incorrect value for "esc_period" (minimum %2$s seconds).', $action['name'], SEC_PER_MIN));
			}
			if (isset($duplicates[$action['name']])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('Action "%1$s" already exists.', $action['name']));
			}
			else {
				$duplicates[$action['name']] = $action['name'];
			}
		}

		$dbActions = $this->get(array(
			'filter' => array('name' => $duplicates),
			'output' => API_OUTPUT_EXTEND,
			'editable' => true,
			'nopermissions' => true
		));
		foreach ($dbActions as $dbAction) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _s('Action "%1$s" already exists.', $dbAction['name']));
		}

		$createdActionIds = DB::insert('actions', $actions, true);

		$conditions = array();
		$operations = array();

		$filterValidator = new CSchemaValidator($this->getFilterSchema());
		$filterConditionValidator = new CSchemaValidator($this->getFilterConditionSchema());

		foreach ($actions as $actionNumber => &$action) {
			$createdActionId = $createdActionIds[$actionNumber];

			$action['actionid'] = $createdActionId;

			if (isset($action['filter'])) {
				$filterValidator->setObjectName($action['name']);
				$this->checkValidator($action['filter'], $filterValidator);

				foreach ($action['filter']['conditions'] as &$condition) {
					$filterConditionValidator->setObjectName($action['name']);
					$this->checkValidator($condition, $filterConditionValidator);
					$condition['actionid'] = $createdActionId;
					$conditions[] = $condition;
				}
				unset($condition);
			}

			if (!isset($action['operations']) || empty($action['operations'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Incorrect parameter for action "%1$s".', $action['name']));
			}
			else {
				foreach ($action['operations'] as &$operation) {
					$operation['actionid'] = $createdActionId;
					$operations[] = $operation;
				}
				unset($operation);
			}
		}
		unset($action);
		$actions = zbx_toHash($actions, 'actionid');

		self::validateConditions($conditions);
		$conditions = $this->addConditions($conditions);

		$conditionsForActions = array();
		foreach($conditions as $condition) {
			$conditionsForActions[$condition['actionid']][$condition['conditionid']] = $condition;
		}

		$actionUpdates = array();
		foreach ($actions as $createdActionId => $action) {
			if (isset($action['filter'])) {
				$values = array('evaltype' => $action['filter']['evaltype']);

				if ($action['filter']['evaltype'] == CONDITION_EVAL_TYPE_EXPRESSION) {
					$formulaIds = array();
					foreach ($conditionsForActions[$createdActionId] as $condition) {
						$formulaIds[$condition['formulaid']] = $condition['conditionid'];
					}

					$values['formula'] = CConditionHelper::replaceLetterIds($action['filter']['formula'], $formulaIds);
				}

				$actionUpdates[] = array(
					'values' => $values,
					'where' => array('actionid' => $createdActionId)
				);
			}
		}
		if ($actionUpdates) {
			DB::update('actions', $actionUpdates);
		}

		$this->validateOperations($operations);
		$this->addOperations($operations);

		return array('actionids' => $createdActionIds);
	}

	/**
	 * Update actions
	 *
	 * @param _array $actions multidimensional array with actions data
	 * @param array $actions[0,...]['actionid']
	 * @param array $actions[0,...]['expression']
	 * @param array $actions[0,...]['description']
	 * @param array $actions[0,...]['type'] OPTIONAL
	 * @param array $actions[0,...]['priority'] OPTIONAL
	 * @param array $actions[0,...]['status'] OPTIONAL
	 * @param array $actions[0,...]['comments'] OPTIONAL
	 * @param array $actions[0,...]['url'] OPTIONAL
	 * @param array $actions[0,...]['filter'] OPTIONAL
	 * @return boolean
	 */
	public function update($actions) {
		$actions = zbx_toArray($actions);
		$actionIds = zbx_objectValues($actions, 'actionid');

		$actionUpdates = array();

		$actionsDb = $this->get(array(
			'actionids' => $actionIds,
			'editable' => true,
			'output' => API_OUTPUT_EXTEND,
			'preservekeys' => true,
			'selectOperations' => API_OUTPUT_EXTEND,
			'selectFilter' => array('formula', 'conditions', 'conditiontype', 'operator', 'value')
		));

		foreach ($actions as $action) {
			if (isset($action['actionid']) && !isset($actionsDb[$action['actionid']])) {
				self::exception(ZBX_API_ERROR_PERMISSIONS,
					_('No permissions to referred object or it does not exist!'));
			}
		}

		// check fields
		$duplicates = array();
		foreach ($actions as $action) {
			$actionName = isset($action['name']) ? $action['name'] : $actionsDb[$action['actionid']]['name'];

			if (!check_db_fields(array('actionid' => null), $action)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s(
					'Incorrect parameters for action update method "%1$s".',
					$actionName
				));
			}

			// check if user changed esc_period for trigger eventsource
			if (isset($action['esc_period'])
					&& $action['esc_period'] < SEC_PER_MIN
					&& $actionsDb[$action['actionid']]['eventsource'] == EVENT_SOURCE_TRIGGERS) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s(
					'Action "%1$s" has incorrect value for "esc_period" (minimum %2$s seconds).',
					$actionName,
					SEC_PER_MIN
				));
			}

			$this->checkNoParameters($action, array('eventsource'), _('Cannot update "%1$s" for action "%2$s".'),
				$actionName
			);

			if (!isset($action['name'])) {
				continue;
			}

			if (isset($duplicates[$action['name']])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('Action "%1$s" already exists.', $action['name']));
			}
			else {
				$duplicates[$action['name']] = $action['name'];
			}
		}

		$operationsCreate = array();
		$operationsUpdate = array();
		$operationIdsDelete = array();
		$conditionsCreate = array();
		$conditionsUpdate = array();
		$conditionIdsDelete = array();

		$filterValidator = new CSchemaValidator($this->getFilterSchema());
		$filterConditionValidator = new CSchemaValidator($this->getFilterConditionSchema());

		foreach ($actions as $action) {
			$actionId = $action['actionid'];

			if (isset($action['name'])) {
				$actionName = $action['name'];

				$actionExists = $this->get(array(
					'filter' => array('name' => $actionName),
					'output' => array('actionid'),
					'editable' => true,
					'nopermissions' => true,
					'preservekeys' => true
				));
				if (($actionExist = reset($actionExists))
						&& (bccomp($actionExist['actionid'], $actionId) != 0)) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _s('Action "%1$s" already exists.', $actionName));
				}
			}
			else {
				$actionName = $actionsDb[$actionId]['name'];
			}

			if (isset($action['filter'])) {

				$filterValidator->setObjectName($actionName);
				$this->checkValidator($action['filter'], $filterValidator);

				$conditionsDb = isset($actionsDb[$actionId]['filter']['conditions'])
					? $actionsDb[$actionId]['filter']['conditions']
					: array();
				$conditionsDb = zbx_toHash($conditionsDb, 'conditionid');

				foreach ($action['filter']['conditions'] as $condition) {
					$filterConditionValidator->setObjectName($actionName);
					$this->checkValidator($condition, $filterConditionValidator);

					if (!isset($condition['conditionid'])) {
						$condition['actionid'] = $actionId;
						$conditionsCreate[] = $condition;
					}
					elseif (isset($conditionsDb[$condition['conditionid']])) {
						// value and operator validation depends on condition type
						$defaultFields = array('conditiontype', 'operator', 'value');
						foreach ($defaultFields as $field) {
							$condition[$field] = isset($condition[$field])
								? $condition[$field]
								: $conditionsDb[$condition['conditionid']][$field];
						}

						$conditionsUpdate[] = $condition;
						unset($conditionsDb[$condition['conditionid']]);
					}
					else {
						self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action filter conditionid.'));
					}
				}

				$action['evaltype'] = $action['filter']['evaltype'];

				$conditionIdsDelete = array_merge($conditionIdsDelete, array_keys($conditionsDb));
			}

			if (isset($action['operations']) && empty($action['operations'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('Action "%1$s" no operations defined.', $actionName));
			}
			elseif (isset($action['operations'])) {
				$this->validateOperations($action['operations']);

				$operationsDb = $actionsDb[$action['actionid']]['operations'];
				$operationsDb = zbx_toHash($operationsDb, 'operationid');
				foreach ($action['operations'] as $operation) {
					$operation['actionid'] = $action['actionid'];

					if (!isset($operation['operationid'])) {
						$operationsCreate[] = $operation;
					}
					elseif (isset($operationsDb[$operation['operationid']])) {
						$operationsUpdate[] = $operation;
						unset($operationsDb[$operation['operationid']]);
					}
					else {
						self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action operationid.'));
					}
				}
				$operationIdsDelete = array_merge($operationIdsDelete, array_keys($operationsDb));
			}

			$actionId = $action['actionid'];
			unset($action['actionid'], $action['conditions'], $action['operations']);
			if (!empty($action)) {
				$actionUpdates[] = array(
					'values' => $action,
					'where' => array('actionid' => $actionId)
				);
			}
		}

		DB::update('actions', $actionUpdates);

		self::validateConditions($conditionsCreate);
		self::validateConditions($conditionsUpdate, true);

		$newConditions = $this->addConditions($conditionsCreate);
		$this->updateConditions($conditionsUpdate);
		if (!empty($conditionIdsDelete)) {
			$this->deleteConditions($conditionIdsDelete);
		}

		$newConditionsForActions = array();
		foreach ($newConditions as $newCondition) {
			$newConditionsForActions[$newCondition['actionid']][$newCondition['formulaid']] = $newCondition;
		}
		foreach ($newConditionsForActions as $actionId => $newConditionsForAction) {
			foreach ($actions as &$action) {
				if (isset($action['actionid']) && $action['actionid'] == $actionId) {
					foreach ($action['filter']['conditions'] as &$condition) {
						if (!isset($condition['conditionid']) && isset($newConditionsForAction[$condition['formulaid']])) {
							$condition['conditionid'] = $newConditionsForAction[$condition['formulaid']]['conditionid'];
						}
					}
					unset($condition);
				}
			}
			unset($action);
		}

		$this->addOperations($operationsCreate);
		$this->updateOperations($operationsUpdate, $actionsDb);
		if (!empty($operationIdsDelete)) {
			$this->deleteOperations($operationIdsDelete);
		}

		$actionUpdates = array();
		foreach ($actions as $action) {
			$updateValues = array();
			if ($action['filter']['evaltype'] == CONDITION_EVAL_TYPE_EXPRESSION) {
				$formulaIds = array();
				foreach ($action['filter']['conditions'] as $condition) {
					$formulaIds[$condition['formulaid']] = $condition['conditionid'];
				}
				$updateValues['formula'] = CConditionHelper::replaceLetterIds($action['filter']['formula'], $formulaIds);
			}
			else {
				$updateValues['formula'] = '';
			}

			$actionUpdates[] = array(
				'values' => $updateValues,
				'where' => array('actionid' => $action['actionid'])
			);
		}
		if ($actionUpdates) {
			DB::update('actions', $actionUpdates);
		}

		return array('actionids' => $actionIds);
	}

	/**
	 * @param array $conditions
	 *
	 * @return mixed
	 */
	protected function addConditions($conditions) {
		foreach ($conditions as $condition) {
			$connectionDbFields = array(
				'actionid' => null,
				'conditiontype' => null
			);
			if (!check_db_fields($connectionDbFields, $condition)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect parameters for condition.'));
			}
		}
		$resultIds = DB::insert('conditions', $conditions, true);

		if ($conditions && $resultIds) {
			reset($resultIds);
			foreach ($conditions as &$condition) {
				$condition['conditionid'] = current($resultIds);
				next($resultIds);
			}
			unset($condition);
		}

		return $conditions;
	}

	protected function updateConditions($conditions) {
		$update = array();
		foreach ($conditions as $condition) {
			$conditionid = $condition['conditionid'];
			unset($condition['conditionid']);
			$update = array(
				'values' => $condition,
				'where' => array('conditionid' => $conditionid)
			);
		}
		DB::update('conditions', $update);

		return $conditions;
	}

	protected function deleteConditions($conditionids) {
		DB::delete('conditions', array('conditionid' => $conditionids));
	}

	protected function addOperations($operations) {
		foreach ($operations as $operation) {
			$operationDbFields = array(
				'actionid' => null,
				'operationtype' => null
			);
			if (!check_db_fields($operationDbFields, $operation)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect parameter for operations.'));
			}
		}

		$operationids = DB::insert('operations', $operations);

		$opmessage = $opcommand = $opmessageGrp = $opmessageUsr = $opcommandHst = $opcommandGrp = $opgroup = $optemplate = array();
		$opconditionInserts = array();
		foreach ($operations as $onum => $operation) {
			$operationid = $operationids[$onum];

			switch ($operation['operationtype']) {
				case OPERATION_TYPE_MESSAGE:
					if (isset($operation['opmessage']) && !empty($operation['opmessage'])) {
						$operation['opmessage']['operationid'] = $operationid;
						$opmessage[] = $operation['opmessage'];
					}
					if (isset($operation['opmessage_usr'])) {
						foreach ($operation['opmessage_usr'] as $user) {
							$opmessageUsr[] = array(
								'operationid' => $operationid,
								'userid' => $user['userid']
							);
						}
					}
					if (isset($operation['opmessage_grp'])) {
						foreach ($operation['opmessage_grp'] as $usrgrp) {
							$opmessageGrp[] = array(
								'operationid' => $operationid,
								'usrgrpid' => $usrgrp['usrgrpid']
							);
						}
					}
					break;
				case OPERATION_TYPE_COMMAND:
					if (isset($operation['opcommand']) && !empty($operation['opcommand'])) {
						$operation['opcommand']['operationid'] = $operationid;
						$opcommand[] = $operation['opcommand'];
					}
					if (isset($operation['opcommand_hst'])) {
						foreach ($operation['opcommand_hst'] as $hst) {
							$opcommandHst[] = array(
								'operationid' => $operationid,
								'hostid' => $hst['hostid']
							);
						}
					}
					if (isset($operation['opcommand_grp'])) {
						foreach ($operation['opcommand_grp'] as $grp) {
							$opcommandGrp[] = array(
								'operationid' => $operationid,
								'groupid' => $grp['groupid']
							);
						}
					}
					break;
				case OPERATION_TYPE_GROUP_ADD:
				case OPERATION_TYPE_GROUP_REMOVE:
					foreach ($operation['opgroup'] as $grp) {
						$opgroup[] = array(
							'operationid' => $operationid,
							'groupid' => $grp['groupid']
						);
					}
					break;
				case OPERATION_TYPE_TEMPLATE_ADD:
				case OPERATION_TYPE_TEMPLATE_REMOVE:
					foreach ($operation['optemplate'] as $tpl) {
						$optemplate[] = array(
							'operationid' => $operationid,
							'templateid' => $tpl['templateid']
						);
					}
					break;
				case OPERATION_TYPE_HOST_ADD:
				case OPERATION_TYPE_HOST_REMOVE:
				case OPERATION_TYPE_HOST_ENABLE:
				case OPERATION_TYPE_HOST_DISABLE:
			}
			if (isset($operation['opconditions'])) {
				foreach ($operation['opconditions'] as $opcondition) {
					$opcondition['operationid'] = $operationid;
					$opconditionInserts[] = $opcondition;
				}
			}
		}
		DB::insert('opconditions', $opconditionInserts);
		DB::insert('opmessage', $opmessage, false);
		DB::insert('opcommand', $opcommand, false);
		DB::insert('opmessage_grp', $opmessageGrp);
		DB::insert('opmessage_usr', $opmessageUsr);
		DB::insert('opcommand_hst', $opcommandHst);
		DB::insert('opcommand_grp', $opcommandGrp);
		DB::insert('opgroup', $opgroup);
		DB::insert('optemplate', $optemplate);

		return true;
	}

	protected function updateOperations($operations, $actionsDb) {
		$operationsUpdate = array();

		// messages
		$opmessageCreate = array();
		$opmessageUpdate = array();
		$opmessageDeleteByOpId = array();

		$opmessageGrpCreate = array();
		$opmessageUsrCreate = array();
		$opmessageGrpDeleteByOpId = array();
		$opmessageUsrDeleteByOpId = array();

		// commands
		$opcommandCreate = array();
		$opcommandUpdate = array();
		$opcommandDeleteByOpId = array();

		$opcommandGrpCreate = array();
		$opcommandHstCreate = array();

		$opcommandGrpDeleteByOpId = array();
		$opcommandHstDeleteByOpId = array();

		// groups
		$opgroupCreate = array();
		$opgroupDeleteByOpId = array();

		// templates
		$optemplateCreate = array();
		$optemplateDeleteByOpId = array();

		$opconditionsCreate = array();

		foreach ($operations as $operation) {
			$operationsDb = zbx_toHash($actionsDb[$operation['actionid']]['operations'], 'operationid');
			$operationDb = $operationsDb[$operation['operationid']];

			$typeChanged = false;
			if (isset($operation['operationtype']) && ($operation['operationtype'] != $operationDb['operationtype'])) {
				$typeChanged = true;

				switch ($operationDb['operationtype']) {
					case OPERATION_TYPE_MESSAGE:
						$opmessageDeleteByOpId[] = $operationDb['operationid'];
						$opmessageGrpDeleteByOpId[] = $operationDb['operationid'];
						$opmessageUsrDeleteByOpId[] = $operationDb['operationid'];
						break;
					case OPERATION_TYPE_COMMAND:
						$opcommandDeleteByOpId[] = $operationDb['operationid'];
						$opcommandHstDeleteByOpId[] = $operationDb['operationid'];
						$opcommandGrpDeleteByOpId[] = $operationDb['operationid'];
						break;
					case OPERATION_TYPE_GROUP_ADD:
						if ($operation['operationtype'] == OPERATION_TYPE_GROUP_REMOVE) {
							break;
						}
					case OPERATION_TYPE_GROUP_REMOVE:
						if ($operation['operationtype'] == OPERATION_TYPE_GROUP_ADD) {
							break;
						}
						$opgroupDeleteByOpId[] = $operationDb['operationid'];
						break;
					case OPERATION_TYPE_TEMPLATE_ADD:
						if ($operation['operationtype'] == OPERATION_TYPE_TEMPLATE_REMOVE) {
							break;
						}
					case OPERATION_TYPE_TEMPLATE_REMOVE:
						if ($operation['operationtype'] == OPERATION_TYPE_TEMPLATE_ADD) {
							break;
						}
						$optemplateDeleteByOpId[] = $operationDb['operationid'];
						break;
				}
			}

			if (!isset($operation['operationtype'])) {
				$operation['operationtype'] = $operationDb['operationtype'];
			}

			switch ($operation['operationtype']) {
				case OPERATION_TYPE_MESSAGE:
					if (!isset($operation['opmessage_grp'])) {
						$operation['opmessage_grp'] = array();
					}
					else {
						zbx_array_push($operation['opmessage_grp'], array('operationid' => $operation['operationid']));
					}

					if (!isset($operation['opmessage_usr'])) {
						$operation['opmessage_usr'] = array();
					}
					else {
						zbx_array_push($operation['opmessage_usr'], array('operationid' => $operation['operationid']));
					}

					if (!isset($operationDb['opmessage_usr'])) {
						$operationDb['opmessage_usr'] = array();
					}
					if (!isset($operationDb['opmessage_grp'])) {
						$operationDb['opmessage_grp'] = array();
					}

					if ($typeChanged) {
						$operation['opmessage']['operationid'] = $operation['operationid'];
						$opmessageCreate[] = $operation['opmessage'];

						$opmessageGrpCreate = array_merge($opmessageGrpCreate, $operation['opmessage_grp']);
						$opmessageUsrCreate = array_merge($opmessageUsrCreate, $operation['opmessage_usr']);
					}
					else {
						$opmessageUpdate[] = array(
							'values' => $operation['opmessage'],
							'where' => array('operationid'=>$operation['operationid'])
						);

						$diff = zbx_array_diff($operation['opmessage_grp'], $operationDb['opmessage_grp'], 'usrgrpid');
						$opmessageGrpCreate = array_merge($opmessageGrpCreate, $diff['first']);

						foreach ($diff['second'] as $omgrp) {
							DB::delete('opmessage_grp', array(
								'usrgrpid' => $omgrp['usrgrpid'],
								'operationid' => $operation['operationid']
							));
						}

						$diff = zbx_array_diff($operation['opmessage_usr'], $operationDb['opmessage_usr'], 'userid');
						$opmessageUsrCreate = array_merge($opmessageUsrCreate, $diff['first']);
						foreach ($diff['second'] as $omusr) {
							DB::delete('opmessage_usr', array(
								'userid' => $omusr['userid'],
								'operationid' => $operation['operationid']
							));
						}
					}
					break;
				case OPERATION_TYPE_COMMAND:
					if (!isset($operation['opcommand_grp'])) {
						$operation['opcommand_grp'] = array();
					}
					else {
						zbx_array_push($operation['opcommand_grp'], array('operationid' => $operation['operationid']));
					}

					if (!isset($operation['opcommand_hst'])) {
						$operation['opcommand_hst'] = array();
					}
					else {
						zbx_array_push($operation['opcommand_hst'], array('operationid' => $operation['operationid']));
					}

					if (!isset($operationDb['opcommand_grp'])) {
						$operationDb['opcommand_grp'] = array();
					}
					if (!isset($operationDb['opcommand_hst'])) {
						$operationDb['opcommand_hst'] = array();
					}

					if ($typeChanged) {
						$operation['opcommand']['operationid'] = $operation['operationid'];
						$opcommandCreate[] = $operation['opcommand'];

						$opcommandGrpCreate = array_merge($opcommandGrpCreate, $operation['opcommand_grp']);
						$opcommandHstCreate = array_merge($opcommandHstCreate, $operation['opcommand_hst']);
					}
					else {
						// clear and reset fields to default values on type change
						if ($operation['opcommand']['type'] == ZBX_SCRIPT_TYPE_GLOBAL_SCRIPT) {
							$operation['opcommand']['command'] = '';
						}
						else {
							$operation['opcommand']['scriptid'] = null;
						}
						if ($operation['opcommand']['type'] != ZBX_SCRIPT_TYPE_CUSTOM_SCRIPT) {
							$operation['opcommand']['execute_on'] = ZBX_SCRIPT_EXECUTE_ON_AGENT;
						}
						if ($operation['opcommand']['type'] != ZBX_SCRIPT_TYPE_SSH
								&& $operation['opcommand']['type'] != ZBX_SCRIPT_TYPE_TELNET) {
							$operation['opcommand']['port'] = '';
							$operation['opcommand']['username'] = '';
							$operation['opcommand']['password'] = '';
						}
						if (!isset($operation['opcommand']['authtype'])) {
							$operation['opcommand']['authtype'] = ITEM_AUTHTYPE_PASSWORD;
						}
						if ($operation['opcommand']['authtype'] == ITEM_AUTHTYPE_PASSWORD) {
							$operation['opcommand']['publickey'] = '';
							$operation['opcommand']['privatekey'] = '';
						}

						$opcommandUpdate[] = array(
							'values' => $operation['opcommand'],
							'where' => array('operationid' => $operation['operationid'])
						);

						$diff = zbx_array_diff($operation['opcommand_grp'], $operationDb['opcommand_grp'], 'groupid');
						$opcommandGrpCreate = array_merge($opcommandGrpCreate, $diff['first']);

						foreach ($diff['second'] as $omgrp) {
							DB::delete('opcommand_grp', array(
								'groupid' => $omgrp['groupid'],
								'operationid' => $operation['operationid']
							));
						}

						$diff = zbx_array_diff($operation['opcommand_hst'], $operationDb['opcommand_hst'], 'hostid');
						$opcommandHstCreate = array_merge($opcommandHstCreate, $diff['first']);
						$opCommandHostIds = zbx_objectValues($diff['second'], 'opcommand_hstid');
						if ($opCommandHostIds) {
							DB::delete('opcommand_hst', array(
								'opcommand_hstid' => $opCommandHostIds
							));
						}
					}
					break;
				case OPERATION_TYPE_GROUP_ADD:
				case OPERATION_TYPE_GROUP_REMOVE:
					if (!isset($operation['opgroup'])) {
						$operation['opgroup'] = array();
					}
					else {
						zbx_array_push($operation['opgroup'], array('operationid' => $operation['operationid']));
					}

					if (!isset($operationDb['opgroup'])) {
						$operationDb['opgroup'] = array();
					}

					$diff = zbx_array_diff($operation['opgroup'], $operationDb['opgroup'], 'groupid');
					$opgroupCreate = array_merge($opgroupCreate, $diff['first']);
					foreach ($diff['second'] as $ogrp) {
						DB::delete('opgroup', array(
							'groupid' => $ogrp['groupid'],
							'operationid' => $operation['operationid']
						));
					}
					break;
				case OPERATION_TYPE_TEMPLATE_ADD:
				case OPERATION_TYPE_TEMPLATE_REMOVE:
					if (!isset($operation['optemplate'])) {
						$operation['optemplate'] = array();
					}
					else {
						zbx_array_push($operation['optemplate'], array('operationid' => $operation['operationid']));
					}

					if (!isset($operationDb['optemplate'])) {
						$operationDb['optemplate'] = array();
					}

					$diff = zbx_array_diff($operation['optemplate'], $operationDb['optemplate'], 'templateid');
					$optemplateCreate = array_merge($optemplateCreate, $diff['first']);

					foreach ($diff['second'] as $otpl) {
						DB::delete('optemplate', array(
							'templateid' => $otpl['templateid'],
							'operationid' => $operation['operationid']
						));
					}
					break;
			}

			if (!isset($operation['opconditions'])) {
				$operation['opconditions'] = array();
			}
			else {
				zbx_array_push($operation['opconditions'], array('operationid' => $operation['operationid']));
			}

			self::validateOperationConditions($operation['opconditions']);

			$diff = zbx_array_diff($operation['opconditions'], $operationDb['opconditions'], 'opconditionid');
			$opconditionsCreate = array_merge($opconditionsCreate, $diff['first']);

			$opconditionsidDelete = zbx_objectValues($diff['second'], 'opconditionid');
			if (!empty($opconditionsidDelete)) {
				DB::delete('opconditions', array('opconditionid' => $opconditionsidDelete));
			}

			$operationid = $operation['operationid'];
			unset($operation['operationid']);
			if (!empty($operation)) {
				$operationsUpdate[] = array(
					'values' => $operation,
					'where' => array('operationid' => $operationid)
				);
			}
		}

		DB::update('operations', $operationsUpdate);

		if (!empty($opmessageDeleteByOpId)) {
			DB::delete('opmessage', array('operationid' => $opmessageDeleteByOpId));
		}
		if (!empty($opcommandDeleteByOpId)) {
			DB::delete('opcommand', array('operationid' => $opcommandDeleteByOpId));
		}
		if (!empty($opmessageGrpDeleteByOpId)) {
			DB::delete('opmessage_grp', array('operationid' => $opmessageGrpDeleteByOpId));
		}
		if (!empty($opmessageUsrDeleteByOpId)) {
			DB::delete('opmessage_usr', array('operationid' => $opmessageUsrDeleteByOpId));
		}
		if (!empty($opcommandHstDeleteByOpId)) {
			DB::delete('opcommand_hst', array('operationid' => $opcommandHstDeleteByOpId));
		}
		if (!empty($opcommandGrpDeleteByOpId)) {
			DB::delete('opcommand_grp', array('operationid' => $opcommandGrpDeleteByOpId));
		}
		if (!empty($opcommandGrpDeleteByOpId)) {
			DB::delete('opcommand_grp', array('opcommand_grpid' => $opcommandGrpDeleteByOpId));
		}
		if (!empty($opcommandHstDeleteByOpId)) {
			DB::delete('opcommand_hst', array('opcommand_hstid' => $opcommandHstDeleteByOpId));
		}
		if (!empty($opgroupDeleteByOpId)) {
			DB::delete('opgroup', array('operationid' => $opgroupDeleteByOpId));
		}
		if (!empty($optemplateDeleteByOpId)) {
			DB::delete('optemplate', array('operationid' => $optemplateDeleteByOpId));
		}

		DB::insert('opmessage', $opmessageCreate, false);
		DB::insert('opcommand', $opcommandCreate, false);
		DB::insert('opmessage_grp', $opmessageGrpCreate);
		DB::insert('opmessage_usr', $opmessageUsrCreate);
		DB::insert('opcommand_grp', $opcommandGrpCreate);
		DB::insert('opcommand_hst', $opcommandHstCreate);
		DB::insert('opgroup', $opgroupCreate);
		DB::insert('optemplate', $optemplateCreate);
		DB::update('opmessage', $opmessageUpdate);
		DB::update('opcommand', $opcommandUpdate);
		DB::insert('opconditions', $opconditionsCreate);
	}

	protected function deleteOperations($operationids) {
		DB::delete('operations', array('operationid' => $operationids));
	}

	/**
	 * Delete actions.
	 *
	 * @param array $actionids
	 *
	 * @return array
	 */
	public function delete(array $actionids) {
		if (empty($actionids)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty input parameter.'));
		}

		$delActions = $this->get(array(
			'actionids' => $actionids,
			'editable' => true,
			'output' => array('actionid'),
			'preservekeys' => true
		));
		foreach ($actionids as $actionid) {
			if (isset($delActions[$actionid])) {
				continue;
			}
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('No permissions to referred object or it does not exist!'));
		}

		DB::delete('actions', array('actionid' => $actionids));
		DB::delete('alerts', array('actionid' => $actionids));

		return array('actionids' => $actionids);
	}

	public function validateOperations($operations) {
		$operations = zbx_toArray($operations);

		foreach ($operations as $operation) {
			if ((isset($operation['esc_step_from']) || isset($operation['esc_step_to'])) && !isset($operation['esc_step_from'], $operation['esc_step_to'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('esc_step_from and esc_step_to must be set together.'));
			}

			if (isset($operation['esc_step_from'], $operation['esc_step_to'])) {
				if ($operation['esc_step_from'] < 1 || $operation['esc_step_to'] < 0) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action operation escalation step values.'));
				}

				if ($operation['esc_step_from'] > $operation['esc_step_to'] && $operation['esc_step_to'] != 0) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action operation escalation step values.'));
				}
			}

			if (isset($operation['esc_period'])) {
				if (isset($operation['esc_period']) && $operation['esc_period'] != 0 && $operation['esc_period'] < SEC_PER_MIN) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action operation step duration.'));
				}
			}

			$hostIdsAll = array();
			$hostGroupIdsAll = array();
			$userIdsAll = array();
			$userGroupIdsAll = array();
			switch ($operation['operationtype']) {
				case OPERATION_TYPE_MESSAGE:
					$userIds = isset($operation['opmessage_usr']) ? zbx_objectValues($operation['opmessage_usr'], 'userid') : array();
					$userGroupIds = isset($operation['opmessage_grp']) ? zbx_objectValues($operation['opmessage_grp'], 'usrgrpid') : array();

					if (empty($userIds) && empty($userGroupIds)) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _('No recipients for action operation message.'));
					}

					$userIdsAll = array_merge($userIdsAll, $userIds);
					$userGroupIdsAll = array_merge($userGroupIdsAll, $userGroupIds);
					break;
				case OPERATION_TYPE_COMMAND:
					if (!isset($operation['opcommand']['type'])) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _('No command type specified for action operation.'));
					}

					if ((!isset($operation['opcommand']['command']) || zbx_empty(trim($operation['opcommand']['command'])))
							&& $operation['opcommand']['type'] != ZBX_SCRIPT_TYPE_GLOBAL_SCRIPT) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s('No command specified for action operation.'));
					}

					switch ($operation['opcommand']['type']) {
						case ZBX_SCRIPT_TYPE_IPMI:
							break;
						case ZBX_SCRIPT_TYPE_CUSTOM_SCRIPT:
							if (!isset($operation['opcommand']['execute_on'])) {
								self::exception(ZBX_API_ERROR_PARAMETERS, _s('No execution target specified for action operation command "%s".', $operation['opcommand']['command']));
							}
							break;
						case ZBX_SCRIPT_TYPE_SSH:
							if (!isset($operation['opcommand']['authtype']) || zbx_empty($operation['opcommand']['authtype'])) {
								self::exception(ZBX_API_ERROR_PARAMETERS, _s('No authentication type specified for action operation command "%s".', $operation['opcommand']['command']));
							}

							if (!isset($operation['opcommand']['username']) || zbx_empty($operation['opcommand']['username'])) {
								self::exception(ZBX_API_ERROR_PARAMETERS, _s('No authentication user name specified for action operation command "%s".', $operation['opcommand']['command']));
							}

							if ($operation['opcommand']['authtype'] == ITEM_AUTHTYPE_PUBLICKEY) {
								if (!isset($operation['opcommand']['publickey']) || zbx_empty($operation['opcommand']['publickey'])) {
									self::exception(ZBX_API_ERROR_PARAMETERS, _s('No public key file specified for action operation command "%s".', $operation['opcommand']['command']));
								}
								if (!isset($operation['opcommand']['privatekey']) || zbx_empty($operation['opcommand']['privatekey'])) {
									self::exception(ZBX_API_ERROR_PARAMETERS, _s('No private key file specified for action operation command "%s".', $operation['opcommand']['command']));
								}
							}
							break;
						case ZBX_SCRIPT_TYPE_TELNET:
							if (!isset($operation['opcommand']['username']) || zbx_empty($operation['opcommand']['username'])) {
								self::exception(ZBX_API_ERROR_PARAMETERS, _s('No authentication user name specified for action operation command "%s".', $operation['opcommand']['command']));
							}
							break;
						case ZBX_SCRIPT_TYPE_GLOBAL_SCRIPT:
							if (!isset($operation['opcommand']['scriptid']) || zbx_empty($operation['opcommand']['scriptid'])) {
								self::exception(ZBX_API_ERROR_PARAMETERS, _('No script specified for action operation command.'));
							}
							$scripts = API::Script()->get(array(
								'output' => array('scriptid','name'),
								'scriptids' => $operation['opcommand']['scriptid'],
								'preservekeys' => true
							));
							if (!isset($scripts[$operation['opcommand']['scriptid']])) {
								self::exception(ZBX_API_ERROR_PARAMETERS, _('Specified script does not exist or you do not have rights on it for action operation command.'));
							}
							break;
						default:
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action operation command type.'));
					}

					if (isset($operation['opcommand']['port']) && !zbx_empty($operation['opcommand']['port'])) {
						if (zbx_ctype_digit($operation['opcommand']['port'])) {
							if ($operation['opcommand']['port'] > 65535 || $operation['opcommand']['port'] < 1) {
								self::exception(ZBX_API_ERROR_PARAMETERS, _s('Incorrect action operation port "%s".', $operation['opcommand']['port']));
							}
						}
						elseif (!preg_match('/^'.ZBX_PREG_EXPRESSION_USER_MACROS.'$/', $operation['opcommand']['port'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _s('Incorrect action operation port "%s".', $operation['opcommand']['port']));
						}
					}

					$groupIds = array();
					if (isset($operation['opcommand_grp'])) {
						$groupIds = zbx_objectValues($operation['opcommand_grp'], 'groupid');
					}

					$hostIds = array();
					$withoutCurrent = true;
					if (isset($operation['opcommand_hst'])) {
						foreach ($operation['opcommand_hst'] as $hstCommand) {
							if ($hstCommand['hostid'] == 0) {
								$withoutCurrent = false;
							}
							else {
								$hostIds[$hstCommand['hostid']] = $hstCommand['hostid'];
							}
						}
					}

					if (empty($groupIds) && empty($hostIds) && $withoutCurrent) {
						if ($operation['opcommand']['type'] == ZBX_SCRIPT_TYPE_GLOBAL_SCRIPT) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _s('You did not specify targets for action operation global script "%s".', $scripts[$operation['opcommand']['scriptid']]['name']));
						}
						else {
							self::exception(ZBX_API_ERROR_PARAMETERS, _s('You did not specify targets for action operation command "%s".', $operation['opcommand']['command']));
						}
					}

					$hostIdsAll = array_merge($hostIdsAll, $hostIds);
					$hostGroupIdsAll = array_merge($hostGroupIdsAll, $groupIds);
					break;
				case OPERATION_TYPE_GROUP_ADD:
				case OPERATION_TYPE_GROUP_REMOVE:
					$groupIds = isset($operation['opgroup']) ? zbx_objectValues($operation['opgroup'], 'groupid') : array();
					if (empty($groupIds)) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _('Operation has no group to operate.'));
					}
					$hostGroupIdsAll = array_merge($hostGroupIdsAll, $groupIds);
					break;
				case OPERATION_TYPE_TEMPLATE_ADD:
				case OPERATION_TYPE_TEMPLATE_REMOVE:
					$templateIds = isset($operation['optemplate']) ? zbx_objectValues($operation['optemplate'], 'templateid') : array();
					if (empty($templateIds)) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _('Operation has no template to operate.'));
					}
					$hostIdsAll = array_merge($hostIdsAll, $templateIds);
					break;
				case OPERATION_TYPE_HOST_ADD:
				case OPERATION_TYPE_HOST_REMOVE:
				case OPERATION_TYPE_HOST_ENABLE:
				case OPERATION_TYPE_HOST_DISABLE:
					break;
				default:
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action operation type.'));
			}
		}

		if (!API::HostGroup()->isWritable($hostGroupIdsAll)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action operation host group. Host group does not exist or you have no access to this host group.'));
		}
		if (!API::Host()->isWritable($hostIdsAll)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action operation host. Host does not exist or you have no access to this host.'));
		}
		if (!API::User()->isReadable($userIdsAll)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action operation user. User does not exist or you have no access to this user.'));
		}
		if (!API::UserGroup()->isReadable($userGroupIdsAll)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action operation user group. User group does not exist or you have no access to this user group.'));
		}

		return true;
	}

	/**
	 * Validate conditions.
	 *
	 * @static
	 *
	 * @param array $conditions
	 * @param int   $conditions['conditiontype']
	 * @param array $conditions['value']
	 *
	 * @return bool
	 */
	public static function validateConditions($conditions, $update = false) {
		$conditions = zbx_toArray($conditions);

		$hostGroupIdsAll = array();
		$templateIdsAll = array();
		$triggerIdsAll = array();
		$hostIdsAll = array();
		$discoveryRuleIdsAll = array();
		$proxyIdsAll = array();

		// build validators
		$timePeriodValidator = new CTimePeriodValidator();
		$discoveryCheckTypeValidator = new CSetValidator(array(
			'values' => array_keys(discovery_check_type2str())
		));
		$discoveryObjectStatusValidator = new CSetValidator(array(
			'values' => array_keys(discovery_object_status2str())
		));
		$triggerSeverityValidator = new CSetValidator(array(
			'values' => array_keys(getSeverityCaption())
		));
		$discoveryObjectValidator = new CSetValidator(array(
			'values' => array_keys(discovery_object2str())
		));
		$triggerValueValidator = new CSetValidator(array(
			'values' => array_keys(trigger_value2str())
		));
		$eventTypeValidator = new CSetValidator(array(
			'values' => array_keys(eventType())
		));

		foreach ($conditions as $condition) {
			// on create operator is mandatory and needs validation, but on update it must be validated only if it's set
			if (!$update || ($update && isset($condition['operator']))) {
				$operatorValidator = new CSetValidator(array(
					'values' => get_operators_by_conditiontype($condition['conditiontype'])
				));
				if (!$operatorValidator->validate($condition['operator'])) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action condition operator.'));
				}
			}

			if (!$update || ($update && isset($condition['value']))) {
				// validate condition values depending on condition type
				switch ($condition['conditiontype']) {
					case CONDITION_TYPE_HOST_GROUP:
						if (!$condition['value']) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty action condition.'));
						}
						$hostGroupIdsAll[$condition['value']] = $condition['value'];
						break;

					case CONDITION_TYPE_TEMPLATE:
						if (!$condition['value']) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty action condition.'));
						}
						$templateIdsAll[$condition['value']] = $condition['value'];
						break;

					case CONDITION_TYPE_TRIGGER:
						if (!$condition['value']) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty action condition.'));
						}
						$triggerIdsAll[$condition['value']] = $condition['value'];
						break;

					case CONDITION_TYPE_HOST:
						if (!$condition['value']) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty action condition.'));
						}
						$hostIdsAll[$condition['value']] = $condition['value'];
						break;

					case CONDITION_TYPE_DRULE:
						if (!$condition['value']) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty action condition.'));
						}
						$discoveryRuleIdsAll[$condition['value']] = $condition['value'];
						break;

					case CONDITION_TYPE_DCHECK:
						if (!$condition['value']) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty action condition.'));
						}
						$proxyIdsAll[$condition['value']] = $condition['value'];
						break;

					case CONDITION_TYPE_PROXY:
						if (!$condition['value']) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty action condition.'));
						}
						$proxyIdsAll[$condition['value']] = $condition['value'];
						break;

					case CONDITION_TYPE_DOBJECT:
						if (zbx_empty($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty action condition.'));
						}
						elseif (!$discoveryObjectValidator->validate($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS,
								_('Incorrect action condition discovery object.'));
						}
						break;

					case CONDITION_TYPE_TIME_PERIOD:
						if (!$timePeriodValidator->validate($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, $timePeriodValidator->getError());
						}
						break;

					case CONDITION_TYPE_DHOST_IP:
						if (zbx_empty($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty action condition.'));
						}
						else if (!validate_ip_range($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS,
								_s('Incorrect action condition ip "%1$s".', $condition['value']));
						}
						break;

					case CONDITION_TYPE_DSERVICE_TYPE:
						if (zbx_empty($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty action condition.'));
						}
						elseif (!$discoveryCheckTypeValidator->validate($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action condition discovery check.'));
						}
						break;

					case CONDITION_TYPE_DSERVICE_PORT:
						if (zbx_empty($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty action condition.'));
						}
						elseif (!validate_port_list($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS,
								_s('Incorrect action condition port "%1$s".', $condition['value']));
						}
						break;

					case CONDITION_TYPE_DSTATUS:
						if (zbx_empty($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty action condition.'));
						}
						elseif (!$discoveryObjectStatusValidator->validate($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS,
								_('Incorrect action condition discovery status.'));
						}
						break;

					case CONDITION_TYPE_MAINTENANCE:
						if (!zbx_empty($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Maintenance action condition value must be empty.'));
						}
						break;

					case CONDITION_TYPE_TRIGGER_SEVERITY:
						if (zbx_empty($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty action condition.'));
						}
						elseif (!$triggerSeverityValidator->validate($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS,
								_('Incorrect action condition trigger severity.'));
						}
						break;

					case CONDITION_TYPE_TRIGGER_VALUE:
						if (zbx_empty($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty action condition.'));
						}
						elseif (!$triggerValueValidator->validate($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action condition trigger value.'));
						}
						break;

					case CONDITION_TYPE_EVENT_TYPE:
						if (zbx_empty($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty action condition.'));
						}
						elseif (!$eventTypeValidator->validate($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action condition event type.'));
						}
					break;

					case CONDITION_TYPE_TRIGGER_NAME:
					case CONDITION_TYPE_DUPTIME:
					case CONDITION_TYPE_DVALUE:
					case CONDITION_TYPE_APPLICATION:
					case CONDITION_TYPE_HOST_NAME:
					case CONDITION_TYPE_HOST_METADATA:
						if (zbx_empty($condition['value'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty action condition.'));
						}
						break;

					default:
						self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action condition type.'));
						break;
				}
			}
		}

		if (!API::HostGroup()->isWritable($hostGroupIdsAll)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action condition host group. Host group does not exist or you have no access to it.'));
		}
		if (!API::Host()->isWritable($hostIdsAll)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action condition host. Host does not exist or you have no access to it.'));
		}
		if (!API::Template()->isWritable($templateIdsAll)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action condition template. Template does not exist or you have no access to it.'));
		}
		if (!API::Trigger()->isWritable($triggerIdsAll)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action condition trigger. Trigger does not exist or you have no access to it.'));
		}
		if (!API::DRule()->isWritable($discoveryRuleIdsAll)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action condition discovery rule. Discovery rule does not exist or you have no access to it.'));
		}
		if (!API::DCheck()->isWritable($proxyIdsAll)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action condition discovery check. Discovery check does not exist or you have no access to it.'));
		}
		if (!API::Proxy()->isWritable($proxyIdsAll)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action condition proxy. Proxy does not exist or you have no access to it.'));
		}

		return true;
	}

	/**
	 * Validate operation conditions.
	 *
	 * @static
	 * @param $conditions
	 * @return bool
	 */
	public static function validateOperationConditions($conditions) {
		$conditions = zbx_toArray($conditions);
		$ackStatuses = array(
			EVENT_ACKNOWLEDGED => 1,
			EVENT_NOT_ACKNOWLEDGED => 1
		);

		foreach ($conditions as $condition) {
			switch ($condition['conditiontype']) {
				case CONDITION_TYPE_EVENT_ACKNOWLEDGED:
					if (!isset($ackStatuses[$condition['value']])) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action operation condition acknowledge type.'));
					}
					break;

				default:
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect action operation condition type.'));
					break;
			}
		}

		return true;
	}

	protected function addRelatedObjects(array $options, array $result) {
		$result = parent::addRelatedObjects($options, $result);

		$actionIds = array_keys($result);

		// adding formulas
		if ($options['selectFilter'] !== null) {
			$formulaRequested = $this->outputIsRequested('formula', $options['selectFilter']);
			$evalFormulaRequested = $this->outputIsRequested('eval_formula', $options['selectFilter']);
			$conditionsRequested = $this->outputIsRequested('conditions', $options['selectFilter']);

			$filters = array();
			foreach ($result as $action) {
				$filters[$action['actionid']] = array(
					'evaltype' => $action['evaltype'],
					'formula' => isset($action['formula']) ? $action['formula'] : ''
				);
			}

			if ($formulaRequested || $evalFormulaRequested || $conditionsRequested) {
				$conditions = API::getApiService()->select('conditions', array(
					'output' => $this->outputExtend($options['selectFilter'], array('actionid', 'conditionid')),
					'filter' => array('actionid' => $actionIds),
					'preservekeys' => true
				));

				$relationMap = $this->createRelationMap($conditions, 'actionid', 'conditionid');
				$filters = $relationMap->mapMany($filters, $conditions, 'conditions');


				foreach ($filters as &$filter) {
					$filter['conditions'] = zbx_toHash($filter['conditions'], 'conditionid');

					// in case of a custom expression - use the given formula
					if ($filter['evaltype'] == CONDITION_EVAL_TYPE_EXPRESSION) {
						$formula = $filter['formula'];
					}
					// in other cases - generate the formula automatically
					else {
						$conditionIds = array_keys(zbx_toHash($conditions, 'conditionid'));
						$formula = CConditionHelper::getFormula(array_combine($conditionIds, $conditionIds), $filter['evaltype']);
					}

					// generate formulaids from the effective formula
					$formulaIds = CConditionHelper::getFormulaIds($formula);
					foreach ($filter['conditions'] as &$condition) {
						$condition['formulaid'] = $formulaIds[$condition['conditionid']];
					}
					unset($condition);

					// generated a letter based formula only for rules with custom expressions
					if ($formulaRequested && $filter['evaltype'] == CONDITION_EVAL_TYPE_EXPRESSION) {
						$filter['formula'] = CConditionHelper::replaceNumericIds($formula, $formulaIds);
					}

					if ($evalFormulaRequested) {
						$filter['eval_formula'] = CConditionHelper::replaceNumericIds($formula, $formulaIds);
					}
				}
				unset($filter);
			}

			// add filters to the result
			foreach ($result as &$action) {
				$action['filter'] = $filters[$action['actionid']];
			}
			unset($action);
		}

		// adding operations
		if ($options['selectOperations'] !== null && $options['selectOperations'] != API_OUTPUT_COUNT) {
			$operations = API::getApiService()->select('operations', array(
				'output' => $this->outputExtend($options['selectOperations'],
					array('operationid', 'actionid', 'operationtype')
				),
				'filter' => array('actionid' => $actionIds),
				'preservekeys' => true
			));
			$relationMap = $this->createRelationMap($operations, 'actionid', 'operationid');
			$operationIds = $relationMap->getRelatedIds();

			if ($this->outputIsRequested('opconditions', $options['selectOperations'])) {
				foreach ($operations as &$operation) {
					$operation['opconditions'] = array();
				}
				unset($operation);

				$res = DBselect('SELECT op.* FROM opconditions op WHERE '.dbConditionInt('op.operationid', $operationIds));
				while ($opcondition = DBfetch($res)) {
					$operations[$opcondition['operationid']]['opconditions'][] = $opcondition;
				}
			}

			$opmessage = $opcommand = $opgroup = $optemplate = array();
			foreach ($operations as $operationid => $operation) {
				switch ($operation['operationtype']) {
					case OPERATION_TYPE_MESSAGE:
						$opmessage[] = $operationid;
						break;
					case OPERATION_TYPE_COMMAND:
						$opcommand[] = $operationid;
						break;
					case OPERATION_TYPE_GROUP_ADD:
					case OPERATION_TYPE_GROUP_REMOVE:
						$opgroup[] = $operationid;
						break;
					case OPERATION_TYPE_TEMPLATE_ADD:
					case OPERATION_TYPE_TEMPLATE_REMOVE:
						$optemplate[] = $operationid;
						break;
					case OPERATION_TYPE_HOST_ADD:
					case OPERATION_TYPE_HOST_REMOVE:
					case OPERATION_TYPE_HOST_ENABLE:
					case OPERATION_TYPE_HOST_DISABLE:
				}
			}

			// get OPERATION_TYPE_MESSAGE data
			if (!empty($opmessage)) {
				if ($this->outputIsRequested('opmessage', $options['selectOperations'])) {
					foreach ($opmessage as $operationId) {
						$operations[$operationId]['opmessage'] = array();
					}

					$dbOpmessages = DBselect(
						'SELECT o.operationid,o.default_msg,o.subject,o.message,o.mediatypeid'.
							' FROM opmessage o'.
							' WHERE '.dbConditionInt('operationid', $opmessage)
					);
					while ($dbOpmessage = DBfetch($dbOpmessages)) {
						$operations[$dbOpmessage['operationid']]['opmessage'] = $dbOpmessage;
					}
				}

				if ($this->outputIsRequested('opmessage_grp', $options['selectOperations'])) {
					foreach ($opmessage as $operationId) {
						$operations[$operationId]['opmessage_grp'] = array();
					}

					$dbOpmessageGrp = DBselect(
						'SELECT og.operationid,og.usrgrpid'.
							' FROM opmessage_grp og'.
							' WHERE '.dbConditionInt('operationid', $opmessage)
					);
					while ($opmessageGrp = DBfetch($dbOpmessageGrp)) {
						$operations[$opmessageGrp['operationid']]['opmessage_grp'][] = $opmessageGrp;
					}
				}

				if ($this->outputIsRequested('opmessage_usr', $options['selectOperations'])) {
					foreach ($opmessage as $operationId) {
						$operations[$operationId]['opmessage_usr'] = array();
					}

					$dbOpmessageUsr = DBselect(
						'SELECT ou.operationid,ou.userid'.
							' FROM opmessage_usr ou'.
							' WHERE '.dbConditionInt('operationid', $opmessage)
					);
					while ($opmessageUsr = DBfetch($dbOpmessageUsr)) {
						$operations[$opmessageUsr['operationid']]['opmessage_usr'][] = $opmessageUsr;
					}
				}
			}

			// get OPERATION_TYPE_COMMAND data
			if (!empty($opcommand)) {
				if ($this->outputIsRequested('opcommand', $options['selectOperations'])) {
					foreach ($opcommand as $operationId) {
						$operations[$operationId]['opcommand'] = array();
					}

					$dbOpcommands = DBselect(
						'SELECT o.*'.
							' FROM opcommand o'.
							' WHERE '.dbConditionInt('operationid', $opcommand)
					);
					while ($dbOpcommand = DBfetch($dbOpcommands)) {
						$operations[$dbOpcommand['operationid']]['opcommand'] = $dbOpcommand;
					}
				}

				if ($this->outputIsRequested('opcommand_hst', $options['selectOperations'])) {
					foreach ($opcommand as $operationId) {
						$operations[$operationId]['opcommand_hst'] = array();
					}

					$dbOpcommandHst = DBselect(
						'SELECT oh.opcommand_hstid,oh.operationid,oh.hostid'.
							' FROM opcommand_hst oh'.
							' WHERE '.dbConditionInt('operationid', $opcommand)
					);
					while ($opcommandHst = DBfetch($dbOpcommandHst)) {
						$operations[$opcommandHst['operationid']]['opcommand_hst'][] = $opcommandHst;
					}
				}

				if ($this->outputIsRequested('opcommand_grp', $options['selectOperations'])) {
					foreach ($opcommand as $operationId) {
						$operations[$operationId]['opcommand_grp'] = array();
					}

					$dbOpcommandGrp = DBselect(
						'SELECT og.opcommand_grpid,og.operationid,og.groupid'.
							' FROM opcommand_grp og'.
							' WHERE '.dbConditionInt('operationid', $opcommand)
					);
					while ($opcommandGrp = DBfetch($dbOpcommandGrp)) {
						$operations[$opcommandGrp['operationid']]['opcommand_grp'][] = $opcommandGrp;
					}
				}
			}

			// get OPERATION_TYPE_GROUP_ADD, OPERATION_TYPE_GROUP_REMOVE data
			if (!empty($opgroup)) {
				if ($this->outputIsRequested('opgroup', $options['selectOperations'])) {
					foreach ($opgroup as $operationId) {
						$operations[$operationId]['opgroup'] = array();
					}

					$dbOpgroup = DBselect(
						'SELECT o.operationid,o.groupid'.
							' FROM opgroup o'.
							' WHERE '.dbConditionInt('operationid', $opgroup)
					);
					while ($opgroup = DBfetch($dbOpgroup)) {
						$operations[$opgroup['operationid']]['opgroup'][] = $opgroup;
					}
				}
			}

			// get OPERATION_TYPE_TEMPLATE_ADD, OPERATION_TYPE_TEMPLATE_REMOVE data
			if (!empty($optemplate)) {
				if ($this->outputIsRequested('optemplate', $options['selectOperations'])) {
					foreach ($optemplate as $operationId) {
						$operations[$operationId]['optemplate'] = array();
					}

					$dbOptemplate = DBselect(
						'SELECT o.operationid,o.templateid'.
							' FROM optemplate o'.
							' WHERE '.dbConditionInt('operationid', $optemplate)
					);
					while ($optemplate = DBfetch($dbOptemplate)) {
						$operations[$optemplate['operationid']]['optemplate'][] = $optemplate;
					}
				}
			}

			$operations = $this->unsetExtraFields($operations, array('operationid', 'actionid' ,'operationtype'),
				$options['selectOperations']
			);
			$result = $relationMap->mapMany($result, $operations, 'operations');
		}

		return $result;
	}

	/**
	 * Returns the parameters for creating a discovery rule filter validator.
	 *
	 * @return array
	 */
	protected function getFilterSchema() {
		return array(
			'validators' => array(
				'evaltype' => new CSetValidator(array(
						'values' => array(
							CONDITION_EVAL_TYPE_OR,
							CONDITION_EVAL_TYPE_AND,
							CONDITION_EVAL_TYPE_AND_OR,
							CONDITION_EVAL_TYPE_EXPRESSION
						),
						'messageInvalid' => _('Incorrect type of calculation for action "%1$s".')
					)),
				'formula' => new CStringValidator(array(
						'empty' => true
					)),
				'conditions' => new CCollectionValidator(array(
						'empty' => true,
						'messageInvalid' => _('Incorrect conditions for action "%1$s".')
					))
			),
			'postValidators' => array(
				new CConditionValidator(array(
					'messageInvalidFormula' => _('Incorrect custom expression "%2$s" for action "%1$s": %3$s.'),
					'messageMissingCondition' => _('Condition "%2$s" used in formula "%3$s" for action "%1$s" is not defined.'),
					'messageUnusedCondition' => _('Condition "%2$s" is not used in formula "%3$s" for action "%1$s".')
				))
			),
			'required' => array('evaltype', 'conditions'),
			'messageRequired' => _('No "%2$s" given for the filter of action "%1$s".'),
			'messageUnsupported' => _('Unsupported parameter "%2$s" for the filter of action "%1$s".')
		);
	}

	/**
	 * Returns the parameters for creating a action filter condition validator.
	 *
	 * @return array
	 */
	protected function getFilterConditionSchema() {
		$conditionTypes = array(
			CONDITION_TYPE_HOST_GROUP, CONDITION_TYPE_HOST, CONDITION_TYPE_TRIGGER, CONDITION_TYPE_TRIGGER_NAME,
			CONDITION_TYPE_TRIGGER_SEVERITY, CONDITION_TYPE_TRIGGER_VALUE, CONDITION_TYPE_TIME_PERIOD,
			CONDITION_TYPE_DHOST_IP, CONDITION_TYPE_DSERVICE_TYPE, CONDITION_TYPE_DSERVICE_PORT,
			CONDITION_TYPE_DSTATUS, CONDITION_TYPE_DUPTIME, CONDITION_TYPE_DVALUE, CONDITION_TYPE_TEMPLATE,
			CONDITION_TYPE_EVENT_ACKNOWLEDGED, CONDITION_TYPE_APPLICATION, CONDITION_TYPE_MAINTENANCE,
			CONDITION_TYPE_DRULE, CONDITION_TYPE_DCHECK, CONDITION_TYPE_PROXY, CONDITION_TYPE_DOBJECT,
			CONDITION_TYPE_HOST_NAME, CONDITION_TYPE_EVENT_TYPE, CONDITION_TYPE_HOST_METADATA
		);

		$operators = array(
			CONDITION_OPERATOR_EQUAL, CONDITION_OPERATOR_NOT_EQUAL, CONDITION_OPERATOR_LIKE,
			CONDITION_OPERATOR_NOT_LIKE, CONDITION_OPERATOR_IN, CONDITION_OPERATOR_MORE_EQUAL,
			CONDITION_OPERATOR_LESS_EQUAL, CONDITION_OPERATOR_NOT_IN
		);

		return array(
			'validators' => array(
				'conditiontype' => new CSetValidator(array(
						'values' => $conditionTypes,
						'messageInvalid' => _('Incorrect filter condition type for action "%1$s".')
					)) ,
				'value' => new CStringValidator(array(
						'empty' => true
					)),
				'formulaid' => new CStringValidator(array(
						'regex' => '/[A-Z]+/',
						'messageEmpty' => _('Empty filter condition formula ID for action "%1$s".'),
						'messageRegex' => _('Incorrect filter condition formula ID for action "%1$s".')
					)),
				'operator' => new CSetValidator(array(
						'values' => $operators,
						'messageInvalid' => _('Incorrect filter condition operator for action "%1$s".')
					)),
				'actionid' => new CIdValidator(array(
						'empty' => true,
						'messageRegex' => _('Incorrect action ID for action "%1$s".')
					)),
				'conditionid' => new CIdValidator(array(
						'empty' => true,
						'messageRegex' => _('Incorrect action condition ID for action "%1$s".')
					)),
			),
			'required' => array('conditiontype', 'value'),
			'messageRequired' => _('No "%2$s" given for a filter condition of action "%1$s".'),
			'messageUnsupported' => _('Unsupported parameter "%2$s" for a filter condition of action "%1$s".')
		);
	}
}
