<?php

class WorkflowSystemMember extends Member {
	static $db = array();
	
	public static function get_one($callerClass, $filter = "", $cache = true, $orderby = "") {
		return DataObject::get_one('WorkflowSystemMember', $filter = "", $cache = true, $orderby = "");
	}
	
	function requireDefaultRecords() {
		parent::requireDefaultRecords();
		if (!self::get()) {
			$su = new WorkflowSystemMember();
			$su->FirstName = 'CMS';
			$su->Surname = 'Workflow';
			$su->write();
			$su->addToGroupByCode('administrators');
			DB::alteration_message("Added CMS Workflow user","created");
		}
	}
}
